<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\Support\Charset;
use Symfony\Component\Console\Input\InputOption;

/**
 * Migra os usuários do MyBB para o Flarum preservando o uid como id.
 *
 * Senhas: o hash original (formato DVZ Hash do MyBB) é COPIADO para a tabela
 * companheira `mybb_legacy_passwords` (algorithm, hash, salt). O verificador
 * `MybbPasswordChecker` aceita o login com a senha original do MyBB, re-hasheia
 * para bcrypt do Flarum no primeiro login bem-sucedido e apaga a linha legada.
 *
 * Grupos / papéis:
 *  - MyBB gid 4 (Administrators)      -> Flarum group 1 (Admin)
 *  - MyBB gid 3 (Super Mods) / 6 (Mods) -> Flarum group 4 (Mod)
 *  - MyBB gid 7 (Banned)              -> suspended_until = 2099-12-31 (flarum/suspend)
 *  - MyBB gid 1/2/5 (Guest/Registered/Awaiting Activation) -> ignorados
 *      (Registered é implícito no Flarum; Awaiting Activation desliga
 *       is_email_confirmed)
 *  - MyBB gid >= 8 (customizados)      -> Flarum group com mesmo id (criado
 *                                         pelo mybb:groups)
 */
class MigrateUsersCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    private const BATCH = 200;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:users')
            ->setDescription('Migrate MyBB users to Flarum, preserving IDs and capturing hashes in mybb_legacy_passwords.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count and report without writing to Flarum.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Migrate at most N users (useful for testing).', null);
        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Run with --force.');
            return 1;
        }

        $dryRun = (bool) $this->input->getOption('dry-run');
        $limit  = $this->input->getOption('limit') ? (int) $this->input->getOption('limit') : null;

        $mybb = $this->buildMybbDatabase($this->settings);
        $prefix = $mybb->prefix();

        $schema = $this->db->getSchemaBuilder();
        $hasSuspend = $schema->hasColumn('users', 'suspended_until');
        $hasBio     = $schema->hasColumn('users', 'bio');
        $hasMarkRead = $schema->hasColumn('users', 'marked_all_as_read_at');
        $hasReadNotif = $schema->hasColumn('users', 'read_notifications_at');

        $total = (int) $mybb->scalar("SELECT COUNT(*) FROM {$prefix}users");
        $this->info("Users in MyBB: {$total}" . ($limit !== null ? " (limit={$limit})" : ''));

        if ($dryRun) {
            $this->info('Dry-run — nothing written.');
            return 0;
        }

        $sql = "SELECT uid, username, email, password, salt, password_algorithm,
                       regdate AS regdate_ts, lastvisit AS lastvisit_ts,
                       postnum, threadnum, usergroup, additionalgroups,
                       signature, website, usertitle
                FROM {$prefix}users
                ORDER BY uid"
                . ($limit ? " LIMIT {$limit}" : '');

        $this->db->statement('SET FOREIGN_KEY_CHECKS=0');

        $userBatch = [];
        $pwBatch = [];
        $groupBatch = [];
        $processed = 0; $skipped = 0; $captured = 0; $banned = 0; $awaiting = 0;
        $adminCount = 0; $modCount = 0;

        try {
            foreach ($mybb->cursor($sql) as $row) {
                $uid = (int) $row['uid'];
                $username = Charset::fix((string) $row['username']);
                $email = trim((string) $row['email']);

                if ($uid <= 0 || $username === '' || $email === '') {
                    $skipped++;
                    continue;
                }

                $regdateTs = (int) ($row['regdate_ts'] ?? 0);
                $lastvisitTs = (int) ($row['lastvisit_ts'] ?? 0);
                $joinedAt = $regdateTs > 0 ? date('Y-m-d H:i:s', $regdateTs) : date('Y-m-d H:i:s');
                $lastSeenAt = $lastvisitTs > 0 ? date('Y-m-d H:i:s', $lastvisitTs) : null;

                $isAwaiting = self::groupSetContains($row, 5);
                $isBanned   = self::groupSetContains($row, 7);

                if ($isAwaiting) $awaiting++;
                if ($isBanned)   $banned++;

                $userRow = [
                    'id' => $uid,
                    'username' => mb_substr($username, 0, 100),
                    'email' => mb_substr($email, 0, 150),
                    'is_email_confirmed' => $isAwaiting ? 0 : 1,
                    'password' => '',
                    'joined_at' => $joinedAt,
                    'last_seen_at' => $lastSeenAt,
                    'discussion_count' => (int) ($row['threadnum'] ?? 0),
                    'comment_count' => (int) ($row['postnum'] ?? 0),
                    'avatar_url' => null,
                    'preferences' => null,
                ];

                if ($hasMarkRead)   $userRow['marked_all_as_read_at'] = null;
                if ($hasReadNotif)  $userRow['read_notifications_at'] = null;

                if ($hasSuspend) {
                    $userRow['suspended_until'] = $isBanned ? '2099-12-31 00:00:00' : null;
                }

                if ($hasBio) {
                    $bio = Charset::fix((string) ($row['signature'] ?? ''));
                    $userRow['bio'] = $bio !== '' ? mb_substr($bio, 0, 4000) : null;
                }

                $userBatch[] = $userRow;

                $password = (string) ($row['password'] ?? '');
                if ($password !== '') {
                    $pwBatch[] = [
                        'user_id' => $uid,
                        'algorithm' => (string) ($row['password_algorithm'] ?? ''),
                        'hash' => mb_substr($password, 0, 255),
                        'salt' => mb_substr((string) ($row['salt'] ?? ''), 0, 16),
                    ];
                    $captured++;
                }

                foreach (self::mappedFlarumGroupIds($row) as $gid) {
                    $groupBatch[] = ['user_id' => $uid, 'group_id' => $gid];
                    if ($gid === 1) $adminCount++;
                    if ($gid === 4) $modCount++;
                }

                $processed++;

                if (count($userBatch) >= self::BATCH) {
                    $this->flush($userBatch, $pwBatch, $groupBatch);
                    if ($processed % 1000 === 0) {
                        $this->info("  $processed/$total");
                    }
                }
            }

            $this->flush($userBatch, $pwBatch, $groupBatch);
        } finally {
            $this->db->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->info("Done.");
        $this->info("  users inserted      : {$processed}");
        $this->info("  skipped (empty uid/user/email): {$skipped}");
        $this->info("  hashes captured     : {$captured}");
        $this->info("  marked banned       : {$banned}");
        $this->info("  awaiting activation : {$awaiting}");
        $this->info("  assigned to Admin   : {$adminCount}");
        $this->info("  assigned to Mod     : {$modCount}");

        return 0;
    }

    /**
     * Faz o insert batch dos 3 alvos (users, mybb_legacy_passwords, group_user)
     * e zera os buffers.
     *
     * @param array<int, array<string, mixed>> $users
     * @param array<int, array<string, mixed>> $pws
     * @param array<int, array<string, mixed>> $groups
     */
    private function flush(array &$users, array &$pws, array &$groups): void
    {
        if ($users !== []) {
            $this->db->table('users')->insert($users);
            $users = [];
        }
        if ($pws !== []) {
            $this->db->table('mybb_legacy_passwords')->insert($pws);
            $pws = [];
        }
        if ($groups !== []) {
            $this->db->table('group_user')->insert($groups);
            $groups = [];
        }
    }

    /**
     * Indica se o conjunto (usergroup + additionalgroups) do MyBB inclui um gid.
     *
     * @param array<string, mixed> $row
     */
    private static function groupSetContains(array $row, int $gid): bool
    {
        if ((int) ($row['usergroup'] ?? 0) === $gid) {
            return true;
        }
        $extra = array_filter(array_map('intval', explode(',', (string) ($row['additionalgroups'] ?? ''))));

        return in_array($gid, $extra, true);
    }

    /**
     * Mapeia o conjunto de gids MyBB para os ids de grupos Flarum a associar.
     *
     * @param array<string, mixed> $row
     * @return array<int, int>
     */
    private static function mappedFlarumGroupIds(array $row): array
    {
        $set = [(int) ($row['usergroup'] ?? 0)];
        $extra = array_filter(array_map('intval', explode(',', (string) ($row['additionalgroups'] ?? ''))));
        $set = array_unique(array_merge($set, $extra));

        $result = [];
        foreach ($set as $gid) {
            if (in_array($gid, [1, 2, 5, 7], true)) {
                continue;
            }
            if ($gid === 4) {
                $result[] = 1;
            } elseif ($gid === 3 || $gid === 6) {
                $result[] = 4;
            } elseif ($gid >= 8) {
                $result[] = $gid;
            }
        }

        return array_values(array_unique($result));
    }
}
