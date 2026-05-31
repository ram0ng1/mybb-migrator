<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Migra as restrições de visualização por fórum do MyBB
 * (dfsmybb_forumpermissions) para as tags do Flarum.
 *
 * Lógica:
 *  - Se um fórum tem QUALQUER entrada com canview=0 em forumpermissions,
 *    significa que pelo menos um grupo foi explicitamente proibido de ver
 *    o fórum. A tag correspondente é marcada com is_restricted=1 (no
 *    Flarum isso significa: invisível salvo permissão explícita).
 *  - Para cada (gid, fid) com canview=1, é concedida a permissão
 *    "tag{fid}.viewForum" ao grupo Flarum mapeado (admin, mod ou custom).
 *  - Adicionalmente, Admin (Flarum 1) e Mod (Flarum 4) sempre recebem
 *    viewForum nas tags restritas para que moderação continue funcionando.
 */
class MigrateForumPermsCommand extends AbstractCommand
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
            ->setName('mybb:forum-perms')
            ->setDescription('Migra restrições por fórum do MyBB (forumpermissions) para is_restricted + tag{fid}.viewForum nas tags do Flarum.')
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

        $tagIds = [];
        foreach ($this->db->table('tags')->select('id')->cursor() as $row) {
            $tagIds[(int) $row->id] = true;
        }
        $groupIds = [];
        foreach ($this->db->table('groups')->select('id')->cursor() as $row) {
            $groupIds[(int) $row->id] = true;
        }

        $deniedByForum = [];
        $allowedByForum = [];

        $stmt = $mybb->select("SELECT fid, gid, canview FROM {$prefix}forumpermissions");

        while ($row = $stmt->fetch()) {
            $fid = (int) $row['fid'];
            $gid = (int) $row['gid'];
            $canview = (int) $row['canview'];

            if (! isset($tagIds[$fid])) {
                continue;
            }

            if ($canview === 1) {
                $allowedByForum[$fid][$gid] = true;
            } else {
                $deniedByForum[$fid][$gid] = true;
            }
        }

        $restrictedTags = 0;
        $permsGranted = 0;

        foreach (array_keys($deniedByForum) as $fid) {
            $this->db->table('tags')->where('id', $fid)->update(['is_restricted' => 1]);
            $restrictedTags++;

            $flarumGroupsAllowed = [];
            foreach (array_keys($allowedByForum[$fid] ?? []) as $mybbGid) {
                $mapped = self::mapMybbGroupToFlarum($mybbGid);
                if ($mapped !== null) {
                    $flarumGroupsAllowed[$mapped] = true;
                }
            }
            $flarumGroupsAllowed[1] = true;
            $flarumGroupsAllowed[4] = true;

            foreach (array_keys($flarumGroupsAllowed) as $fGid) {
                if (! isset($groupIds[$fGid])) {
                    continue;
                }
                $perm = "tag{$fid}.viewForum";
                $exists = $this->db->table('group_permission')
                    ->where('group_id', $fGid)
                    ->where('permission', $perm)
                    ->exists();
                if (! $exists) {
                    $this->db->table('group_permission')->insert([
                        'group_id' => $fGid,
                        'permission' => $perm,
                    ]);
                    $permsGranted++;
                }
            }
        }

        $this->info('Concluído.');
        $this->info("  tags restritas         : {$restrictedTags}");
        $this->info("  permissões concedidas  : {$permsGranted}");

        return 0;
    }

    /**
     * Mapeia gid do MyBB para gid do Flarum. Retorna null quando não há
     * mapeamento sensato (Awaiting Activation, Banned, ou Registered=2 que
     * é implícito no Flarum como Member=3).
     */
    private static function mapMybbGroupToFlarum(int $gid): ?int
    {
        return match (true) {
            $gid === 1 => 2,
            $gid === 4 => 1,
            $gid === 3 || $gid === 6 => 4,
            $gid === 2 => 3,
            $gid >= 8 => $gid,
            default => null,
        };
    }
}
