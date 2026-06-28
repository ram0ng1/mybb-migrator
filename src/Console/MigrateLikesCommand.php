<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Migra as curtidas (~2,77M) do MyBB para flarum/likes.
 *
 * A tabela do MyBB pode usar `like_date` (simplelikes) ou `dateline`. O comando
 * detecta dinamicamente. Curtidas referenciando posts/usuários que não foram
 * migrados são puladas sem erro (skip silencioso, contado no relatório final).
 */
class MigrateLikesCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    private const BATCH = 5000;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:likes')
            ->setDescription('Migrate MyBB likes (dfsmybb_post_likes) to flarum/likes.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.')
            ->addOption('recover-likers', null, InputOption::VALUE_NONE,
                'Recupera curtidas de usuários apagados criando contas-fantasma (deleted-<uid>) para preservar a contagem. Curtidas de posts inexistentes seguem impossíveis.');
        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Run with --force.');
            return 1;
        }

        $mybb = $this->buildMybbDatabase($this->settings);
        $prefix = $mybb->prefix();
        $table = "{$prefix}post_likes";

        $columns = [];
        foreach ($mybb->select("SHOW COLUMNS FROM {$table}") as $col) {
            $columns[strtolower((string) $col['Field'])] = true;
        }

        $postColumn = self::firstAvailable($columns, ['pid', 'post_id', 'postid']);
        $userColumn = self::firstAvailable($columns, ['uid', 'user_id', 'userid', 'liker_uid', 'liker_id']);
        $dateColumn = self::firstAvailable($columns, ['like_date', 'dateline', 'liked_at', 'created_at']);

        if ($postColumn === null || $userColumn === null) {
            $this->error("Table {$table} has no recognized post/user columns. Columns seen: " . implode(',', array_keys($columns)));
            return 1;
        }

        $this->info("Detected columns: post={$postColumn}, user={$userColumn}, date=" . ($dateColumn ?? '(none)'));

        $dateSelect = $dateColumn !== null ? "{$dateColumn} AS ts" : '0 AS ts';
        $total = (int) $mybb->scalar("SELECT COUNT(*) FROM {$table}");
        $this->info("Likes to migrate: {$total}");

        $this->db->table('post_likes')->truncate();
        $this->db->getSchemaBuilder()->disableForeignKeyConstraints();

        $postIds = $this->loadIdSet('posts');
        $userIds = $this->loadIdSet('users');

        $recover = (bool) $this->input->getOption('recover-likers');

        $batch = [];
        $inserted = 0; $skipped = 0; $skippedNoPost = 0; $skippedNoUser = 0;
        $ghosts = []; $recoveredLikes = 0; $warnings = [];

        try {
            $sql = "SELECT {$postColumn} AS pid, {$userColumn} AS uid, {$dateSelect} FROM {$table} ORDER BY {$postColumn}";
            foreach ($mybb->cursor($sql) as $row) {
                $pid = (int) $row['pid'];
                $uid = (int) $row['uid'];

                // Post inexistente no Flarum (apagado no MyBB): irrecuperável —
                // não há onde anexar a curtida.
                if (! isset($postIds[$pid])) {
                    $skipped++; $skippedNoPost++;
                    continue;
                }

                // Usuário ausente (conta apagada do MyBB). Sem --recover-likers,
                // pula. Com a flag, cria uma conta-fantasma (deleted-<uid>) p/
                // preservar a curtida e a contagem do post.
                if (! isset($userIds[$uid])) {
                    if (! $recover) {
                        $skipped++; $skippedNoUser++;
                        continue;
                    }
                    $this->ensureGhostUser($uid, $userIds, $ghosts, $warnings);
                    if (! isset($userIds[$uid])) {
                        // criação falhou (aviso já registrado) — pula sem travar
                        $skipped++; $skippedNoUser++;
                        continue;
                    }
                }
                if (isset($ghosts[$uid])) {
                    $recoveredLikes++;
                }

                $ts = (int) ($row['ts'] ?? 0);
                $batch[] = [
                    'post_id' => $pid,
                    'user_id' => $uid,
                    'created_at' => $ts > 0 && $dateColumn !== null
                        ? (is_numeric($row['ts']) ? date('Y-m-d H:i:s', $ts) : (string) $row['ts'])
                        : date('Y-m-d H:i:s'),
                ];

                if (count($batch) >= self::BATCH) {
                    $this->db->table('post_likes')->insert($batch);
                    $inserted += count($batch);
                    $batch = [];
                    if ($inserted % 50000 === 0) {
                        $this->info("  {$inserted}/{$total}");
                    }
                }
            }

            if ($batch !== []) {
                $this->db->table('post_likes')->insert($batch);
                $inserted += count($batch);
            }
        } finally {
            $this->db->getSchemaBuilder()->enableForeignKeyConstraints();
        }

        $this->info("Done. inserted={$inserted}, skipped={$skipped}");
        $this->info("  skipped (post inexistente no Flarum): {$skippedNoPost}");
        $this->info("  skipped (usuário ausente): {$skippedNoUser}");
        if ($recover) {
            $this->info("  contas-fantasma criadas: " . count($ghosts));
            $this->info("  curtidas recuperadas: {$recoveredLikes}");
        }
        foreach ($warnings as $w) {
            $this->info('⚠ ' . $w);
        }

        return 0;
    }

    /**
     * Cria (uma vez) uma conta-fantasma para um liker apagado do MyBB, com o uid
     * preservado, para que a curtida possa ser anexada e a contagem do post
     * fique fiel. Conta inerte: e-mail sintético, sem senha, não confirmada.
     *
     * @param array<int, bool> $userIds  conjunto de ids válidos (atualizado)
     * @param array<int, bool> $ghosts   uids criados nesta execução (atualizado)
     * @param array<int, string> $warnings
     */
    private function ensureGhostUser(int $uid, array &$userIds, array &$ghosts, array &$warnings): void
    {
        if (isset($userIds[$uid])) {
            return;
        }

        try {
            $this->db->table('users')->insert([
                'id'                 => $uid,
                'username'           => 'deleted-' . $uid,
                'email'              => 'deleted-' . $uid . '@deleted.invalid',
                'is_email_confirmed' => 0,
                'password'           => '',
                'joined_at'          => date('Y-m-d H:i:s'),
                'discussion_count'   => 0,
                'comment_count'      => 0,
            ]);
            $userIds[$uid] = true;
            $ghosts[$uid] = true;
        } catch (\Throwable $e) {
            if (count($warnings) < 50) {
                $warnings[] = "conta-fantasma uid={$uid} não criada: " . trim(explode("\n", $e->getMessage())[0]);
            }
        }
    }

    /**
     * @return array<int, bool>
     */
    private function loadIdSet(string $table): array
    {
        $set = [];
        foreach ($this->db->table($table)->select('id')->cursor() as $row) {
            $set[(int) $row->id] = true;
        }
        return $set;
    }

    /**
     * Devolve o primeiro candidato presente no mapa de colunas, ou null.
     *
     * @param array<string, bool> $columns
     * @param array<int, string> $candidates
     */
    private static function firstAvailable(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $name) {
            if (isset($columns[$name])) {
                return $name;
            }
        }
        return null;
    }
}
