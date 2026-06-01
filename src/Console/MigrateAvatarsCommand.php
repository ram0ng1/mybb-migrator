<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Faz backfill do `users.avatar_url` a partir do campo `avatar` do MyBB.
 *
 * Lê os usuários já migrados (avatar_url NULL) e, para os que tinham
 * avatartype='upload' no MyBB, extrai o basename (ex.: avatar_2.png) e seta
 * em users.avatar_url. Por padrão exige que o arquivo exista em
 * public/assets/avatars antes de marcar — assim avatares quebrados não são
 * apontados. Use --skip-file-check para apontar sem checar.
 */
class MigrateAvatarsCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected Paths $paths,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:avatars')
            ->setDescription('Backfill users.avatar_url for migrated users, using the basename of the MyBB file.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.')
            ->addOption('skip-file-check', null, InputOption::VALUE_NONE, 'Do not check whether the file exists in public/assets/avatars.');
        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Run with --force.');
            return 1;
        }

        $skipFileCheck = (bool) $this->input->getOption('skip-file-check');
        $avatarsDir = $this->paths->public . '/assets/avatars';

        $this->info("Avatars directory: {$avatarsDir}");

        if (! $skipFileCheck && ! is_dir($avatarsDir)) {
            $this->error("Directory {$avatarsDir} does not exist. Run with --skip-file-check to ignore.");
            return 1;
        }

        $mybb = $this->buildMybbDatabase($this->settings);
        $prefix = $mybb->prefix();

        $rows = $mybb->select(
            "SELECT uid, avatar, avatartype FROM {$prefix}users
             WHERE avatar <> '' AND avatartype = 'upload'
             ORDER BY uid"
        );

        $userIds = $this->loadIdSet('users');
        $applied = 0; $missingUser = 0; $missingFile = 0; $invalidName = 0;

        while ($row = $rows->fetch()) {
            $uid = (int) $row['uid'];

            if (! isset($userIds[$uid])) {
                $missingUser++;
                continue;
            }

            $raw = (string) $row['avatar'];
            $path = explode('?', $raw)[0];
            $basename = basename($path);

            if ($basename === '' || ! preg_match('/^[A-Za-z0-9._-]{1,200}$/', $basename)) {
                $invalidName++;
                continue;
            }

            if (! $skipFileCheck && ! is_file($avatarsDir . DIRECTORY_SEPARATOR . $basename)) {
                $missingFile++;
                continue;
            }

            $this->db->table('users')->where('id', $uid)->update(['avatar_url' => $basename]);
            $applied++;

            if ($applied % 200 === 0) {
                $this->info("  {$applied} pointed...");
            }
        }

        $this->info('Done.');
        $this->info("  avatars pointed           : {$applied}");
        $this->info("  MyBB user without Flarum user : {$missingUser}");
        $this->info("  file missing on disk      : {$missingFile}");
        $this->info("  invalid basename          : {$invalidName}");

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
