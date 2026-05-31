<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\MybbDatabase;
use Ramon\MybbMigrator\Support\Charset;
use Symfony\Component\Console\Input\InputOption;

/**
 * Migra enquetes (`mybb_polls`) e votos (`mybb_pollvotes`) do MyBB para a
 * extensão fof/polls. A enquete do MyBB é vinculada à thread (`tid`); em
 * fof/polls 3.x o vínculo é com o primeiro post (`polls.post_id`), de modo
 * que o comando resolve `discussions.first_post_id` antes de inserir.
 *
 * As configurações (`public_poll`, `allow_multiple_votes`, `max_votes`,
 * `hide_votes`, `allow_change_vote`) vivem na coluna JSON `settings`. O
 * `published_at` é populado com o `created_at` para que as enquetes não
 * fiquem como rascunho.
 *
 * Idempotente: trunca `poll_votes`, `poll_options`, `poll_groups` e `polls`
 * antes da carga (FKs desabilitadas durante o processo). Exige --force.
 */
class MigratePollsCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:polls')
            ->setDescription('Migra enquetes e votos do MyBB para fof/polls.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Pula a confirmação interativa.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Não escreve no banco; apenas reporta o plano.');

        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Comando destrutivo. Rode com --force para confirmar.');
            return 1;
        }

        $dryRun = (bool) $this->input->getOption('dry-run');
        $mybb = $this->buildMybbDatabase($this->settings);

        if (! $dryRun) {
            $this->wipeFlarumPollsTables();
        } else {
            $this->info('[dry-run] limpeza das tabelas de polls suprimida.');
        }

        $stats = [
            'polls_created'    => 0,
            'options_created'  => 0,
            'votes_created'    => 0,
            'polls_skipped'    => 0,
            'votes_skipped'    => 0,
        ];

        $pollRows = $mybb->select('SELECT pid, tid, question, dateline, options, timeout, multiple, public, maxoptions FROM ' . $mybb->table('polls'))->fetchAll();

        foreach ($pollRows as $row) {
            $result = $this->migrateSinglePoll($mybb, $row, $dryRun);

            if ($result === null) {
                $stats['polls_skipped']++;
                continue;
            }

            $stats['polls_created']++;
            $stats['options_created'] += $result['options_created'];
            $stats['votes_created']   += $result['votes_created'];
            $stats['votes_skipped']   += $result['votes_skipped'];
        }

        $this->info('Migração concluída:');
        $this->info(sprintf('  polls criadas:    %d', $stats['polls_created']));
        $this->info(sprintf('  opções criadas:   %d', $stats['options_created']));
        $this->info(sprintf('  votos criados:    %d', $stats['votes_created']));
        $this->info(sprintf('  polls puladas:    %d (discussão ausente ou sem opções)', $stats['polls_skipped']));
        $this->info(sprintf('  votos pulados:    %d (usuário ou opção ausente)', $stats['votes_skipped']));

        return 0;
    }

    /**
     * Trunca as tabelas-alvo de fof/polls com FK_CHECKS=0, na ordem de
     * dependência (filhas antes das pais).
     */
    protected function wipeFlarumPollsTables(): void
    {
        $this->info('Limpando tabelas atuais de fof/polls...');
        $this->db->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach (['poll_votes', 'poll_options', 'poll_groups', 'polls'] as $table) {
                if ($this->db->getSchemaBuilder()->hasTable($table)) {
                    $this->db->table($table)->truncate();
                    $this->info("  truncated: $table");
                }
            }
        } finally {
            $this->db->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{options_created:int, votes_created:int, votes_skipped:int}|null
     */
    protected function migrateSinglePoll(MybbDatabase $mybb, array $row, bool $dryRun): ?array
    {
        $pid = (int) $row['pid'];
        $tid = (int) $row['tid'];

        $discussion = $this->db->table('discussions')
            ->where('id', $tid)
            ->select(['id', 'first_post_id', 'user_id'])
            ->first();

        if ($discussion === null) {
            $this->info("  pulada poll #$pid: discussão tid=$tid não existe em flarum.discussions");
            return null;
        }

        $postId = (int) ($discussion->first_post_id ?? 0);
        if ($postId === 0) {
            $firstPost = $this->db->table('posts')
                ->where('discussion_id', $tid)
                ->where('number', 1)
                ->orderBy('id')
                ->select(['id', 'user_id'])
                ->first();
            $postId = $firstPost !== null ? (int) $firstPost->id : 0;
            $authorId = $firstPost !== null ? (int) ($firstPost->user_id ?? 0) : (int) ($discussion->user_id ?? 0);
        } else {
            $firstPost = $this->db->table('posts')
                ->where('id', $postId)
                ->select(['user_id'])
                ->first();
            $authorId = $firstPost !== null ? (int) ($firstPost->user_id ?? 0) : (int) ($discussion->user_id ?? 0);
        }

        if ($postId === 0) {
            $this->info("  pulada poll #$pid: nenhum post inicial localizado para tid=$tid");
            return null;
        }

        $labels = PollOptionsParser::parse((string) $row['options']);
        if ($labels === []) {
            $this->info("  pulada poll #$pid: lista de opções vazia ou ilegível");
            return null;
        }

        $labels = array_map(fn (string $label) => Charset::fix($label), $labels);

        $dateline = (int) $row['dateline'];
        $timeout = (int) $row['timeout'];
        $createdAt = date('Y-m-d H:i:s', $dateline);
        $endDate = $timeout > 0 ? date('Y-m-d H:i:s', $dateline + $timeout * 86400) : null;

        $settings = [
            'public_poll'          => (bool) $row['public'],
            'allow_multiple_votes' => (bool) $row['multiple'],
            'max_votes'            => (int) $row['maxoptions'],
            'hide_votes'           => false,
            'allow_change_vote'    => true,
        ];

        if ($dryRun) {
            return [
                'options_created' => count($labels),
                'votes_created'   => 0,
                'votes_skipped'   => 0,
            ];
        }

        $pollId = (int) $this->db->table('polls')->insertGetId([
            'question'     => Charset::fix((string) $row['question']),
            'subtitle'     => null,
            'image'        => null,
            'image_alt'    => null,
            'post_id'      => $postId,
            'user_id'      => $authorId > 0 ? $authorId : null,
            'end_date'     => $endDate,
            'published_at' => $createdAt,
            'created_at'   => $createdAt,
            'updated_at'   => $createdAt,
            'vote_count'   => 0,
            'settings'     => json_encode($settings, JSON_UNESCAPED_UNICODE),
        ]);

        $optionMap = [];
        foreach ($labels as $index => $answer) {
            $optionId = (int) $this->db->table('poll_options')->insertGetId([
                'answer'     => mb_substr($answer, 0, 255),
                'poll_id'    => $pollId,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'vote_count' => 0,
            ]);
            $optionMap[$index + 1] = $optionId;
        }

        $votesCreated = 0;
        $votesSkipped = 0;
        $optionTallies = array_fill_keys(array_values($optionMap), 0);

        $voteStmt = $mybb->select(
            'SELECT uid, voteoption, dateline FROM ' . $mybb->table('pollvotes') . ' WHERE pid = ?',
            [$pid]
        );

        foreach ($voteStmt as $voteRow) {
            $userId = (int) $voteRow['uid'];
            $option = (int) $voteRow['voteoption'];

            if (! isset($optionMap[$option])) {
                $votesSkipped++;
                continue;
            }
            if ($userId > 0 && ! $this->userExists($userId)) {
                $votesSkipped++;
                continue;
            }

            $voteCreatedAt = date('Y-m-d H:i:s', (int) $voteRow['dateline']);
            $this->db->table('poll_votes')->insert([
                'poll_id'    => $pollId,
                'option_id'  => $optionMap[$option],
                'user_id'    => $userId > 0 ? $userId : null,
                'created_at' => $voteCreatedAt,
                'updated_at' => $voteCreatedAt,
            ]);

            $optionTallies[$optionMap[$option]]++;
            $votesCreated++;
        }

        foreach ($optionTallies as $optionId => $count) {
            if ($count > 0) {
                $this->db->table('poll_options')->where('id', $optionId)->update(['vote_count' => $count]);
            }
        }

        if ($votesCreated > 0) {
            $this->db->table('polls')->where('id', $pollId)->update(['vote_count' => $votesCreated]);
        }

        return [
            'options_created' => count($labels),
            'votes_created'   => $votesCreated,
            'votes_skipped'   => $votesSkipped,
        ];
    }

    /**
     * Cache leve de existência de usuário; o universo do fórum cabe em
     * memória sem custo, e poupa centenas de SELECTs em /pollvotes.
     *
     * @var array<int, bool>
     */
    protected array $userExistsCache = [];

    protected function userExists(int $userId): bool
    {
        if (isset($this->userExistsCache[$userId])) {
            return $this->userExistsCache[$userId];
        }

        $exists = $this->db->table('users')->where('id', $userId)->exists();

        return $this->userExistsCache[$userId] = $exists;
    }
}
