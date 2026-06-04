<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\Support\Charset;
use Symfony\Component\Console\Input\InputOption;

/**
 * Migra os grupos customizados do MyBB (type=2, gid>=8) para o Flarum,
 * preservando o gid como id do grupo Flarum. Grupos do core MyBB (1..7)
 * não viram grupos no Flarum: admin (4) vira pertencimento ao grupo 1 do
 * Flarum, super-mod (3) e mod (6) viram grupo 4 (Mod), banidos (7) viram
 * suspensão via flarum/suspend. Tudo isso é feito no MigrateUsersCommand.
 */
class MigrateGroupsCommand extends AbstractCommand
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
            ->setName('mybb:groups')
            ->setDescription('Migrate custom MyBB groups (type=2, gid>=8) to Flarum while preserving IDs.')
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

        $rows = $mybb->select("SELECT gid, title, description FROM {$prefix}usergroups WHERE type = 2 ORDER BY gid");
        $inserted = 0;
        $skipped = 0;

        $this->db->getSchemaBuilder()->disableForeignKeyConstraints();

        try {
            while ($row = $rows->fetch()) {
                $gid = (int) $row['gid'];

                if ($gid <= 4) {
                    $skipped++;
                    continue;
                }

                $title = Charset::fix((string) $row['title']);

                if ($title === '') {
                    $title = "Group $gid";
                }

                $this->db->table('groups')->updateOrInsert(
                    ['id' => $gid],
                    [
                        'name_singular' => $title,
                        'name_plural'   => $title,
                        'color'         => '#' . str_pad(dechex(mt_rand(0x202020, 0xDFDFDF)), 6, '0', STR_PAD_LEFT),
                        'icon'          => null,
                        'is_hidden'     => 0,
                    ]
                );
                $inserted++;
                $this->info("  group {$gid}: {$title}");
            }
        } finally {
            $this->db->getSchemaBuilder()->enableForeignKeyConstraints();
        }

        $this->info("Custom groups migrated: {$inserted} (skipped gid<=4: {$skipped})");

        return 0;
    }
}
