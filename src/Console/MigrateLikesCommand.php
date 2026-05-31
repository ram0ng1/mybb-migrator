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
            ->setDescription('Migra as curtidas do MyBB (dfsmybb_post_likes) para flarum/likes.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirma execução.');
        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Rode com --force.');
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
            $this->error("Tabela {$table} não tem colunas post/user reconhecidas. Colunas vistas: " . implode(',', array_keys($columns)));
            return 1;
        }

        $this->info("Colunas detectadas: post={$postColumn}, user={$userColumn}, date=" . ($dateColumn ?? '(nenhuma)'));

        $dateSelect = $dateColumn !== null ? "{$dateColumn} AS ts" : '0 AS ts';
        $total = (int) $mybb->scalar("SELECT COUNT(*) FROM {$table}");
        $this->info("Curtidas a migrar: {$total}");

        $this->db->table('post_likes')->truncate();
        $this->db->statement('SET FOREIGN_KEY_CHECKS=0');

        $postIds = $this->loadIdSet('posts');
        $userIds = $this->loadIdSet('users');

        $batch = [];
        $inserted = 0; $skipped = 0;

        try {
            $sql = "SELECT {$postColumn} AS pid, {$userColumn} AS uid, {$dateSelect} FROM {$table} ORDER BY {$postColumn}";
            foreach ($mybb->cursor($sql) as $row) {
                $pid = (int) $row['pid'];
                $uid = (int) $row['uid'];

                if (! isset($postIds[$pid]) || ! isset($userIds[$uid])) {
                    $skipped++;
                    continue;
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
            $this->db->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->info("Concluído. inseridas={$inserted}, puladas={$skipped}");
        return 0;
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
