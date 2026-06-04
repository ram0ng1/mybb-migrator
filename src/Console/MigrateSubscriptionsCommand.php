<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Migra assinaturas de tópicos (dfsmybb_threadsubscriptions) para a coluna
 * `subscription='follow'` da pivot discussion_user, e assinaturas de fóruns
 * (dfsmybb_forumsubscriptions) para tag_user.
 */
class MigrateSubscriptionsCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    private const BATCH = 2000;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:subscriptions')
            ->setDescription('Migrate MyBB thread and forum subscriptions to flarum/subscriptions.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.');
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

        $userIds = $this->loadIdSet('users');
        $discussionIds = $this->loadIdSet('discussions');
        $tagIds = $this->loadIdSet('tags');

        $this->db->getSchemaBuilder()->disableForeignKeyConstraints();

        $threadInserted = 0; $threadSkipped = 0;
        $forumInserted = 0; $forumSkipped = 0;

        try {
            $batch = [];
            foreach ($mybb->cursor("SELECT tid, uid FROM {$prefix}threadsubscriptions") as $row) {
                $tid = (int) $row['tid'];
                $uid = (int) $row['uid'];

                if (! isset($discussionIds[$tid]) || ! isset($userIds[$uid])) {
                    $threadSkipped++;
                    continue;
                }

                $batch[] = [
                    'user_id' => $uid,
                    'discussion_id' => $tid,
                    'subscription' => 'follow',
                ];

                if (count($batch) >= self::BATCH) {
                    $this->db->table('discussion_user')->upsert(
                        $batch,
                        ['user_id', 'discussion_id'],
                        ['subscription']
                    );
                    $threadInserted += count($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->db->table('discussion_user')->upsert($batch, ['user_id', 'discussion_id'], ['subscription']);
                $threadInserted += count($batch);
            }

            if ($this->db->getSchemaBuilder()->hasColumn('tag_user', 'subscription')) {
                $batch = [];
                foreach ($mybb->cursor("SELECT fid, uid FROM {$prefix}forumsubscriptions") as $row) {
                    $fid = (int) $row['fid'];
                    $uid = (int) $row['uid'];

                    if (! isset($tagIds[$fid]) || ! isset($userIds[$uid])) {
                        $forumSkipped++;
                        continue;
                    }

                    $batch[] = [
                        'user_id' => $uid,
                        'tag_id'  => $fid,
                        'subscription' => 'follow',
                    ];

                    if (count($batch) >= self::BATCH) {
                        $this->db->table('tag_user')->upsert($batch, ['user_id', 'tag_id'], ['subscription']);
                        $forumInserted += count($batch);
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    $this->db->table('tag_user')->upsert($batch, ['user_id', 'tag_id'], ['subscription']);
                    $forumInserted += count($batch);
                }
            } else {
                $this->info('  tag_user.subscription does not exist — forum subscriptions were not migrated.');
            }
        } finally {
            $this->db->getSchemaBuilder()->enableForeignKeyConstraints();
        }

        $this->info("Done.");
        $this->info("  thread subscriptions: inserted={$threadInserted}, skipped={$threadSkipped}");
        $this->info("  forum subscriptions : inserted={$forumInserted}, skipped={$forumSkipped}");

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
}
