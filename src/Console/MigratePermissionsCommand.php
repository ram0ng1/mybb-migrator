<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Insere as permissões padrão para os grupos do Flarum (Guest=2, Member=3,
 * Mod=4) e para os grupos customizados migrados do MyBB (gid >= 8).
 *
 * Member recebe o conjunto base de uso (ver fórum, abrir discussão, responder,
 * curtir, denunciar). Mod recebe o conjunto base + permissões de moderação
 * (editar/apagar posts, fechar, fixar, ocultar). Guest pode ver fórum.
 * Grupos customizados recebem o conjunto base de membro (ver fórum + responder).
 * Idempotente: chega/insere uma a uma, ignorando duplicatas.
 */
class MigratePermissionsCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:permissions')
            ->setDescription('Configure default Flarum permissions + migrated custom groups.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Run with --force.');
            return 1;
        }

        $base = ['viewForum', 'viewUserList', 'searchUsers'];
        $member = [...$base, 'startDiscussion', 'discussion.reply', 'discussion.likePosts', 'discussion.flagPosts'];
        $mod = [...$member, 'discussion.editPosts', 'discussion.deletePosts', 'discussion.delete',
                'discussion.hide', 'discussion.lock', 'discussion.sticky', 'discussion.rename',
                'discussion.viewIpsOfPosts', 'discussion.changeTags'];

        $grants = [
            2 => ['viewForum'],
            3 => $member,
            4 => $mod,
        ];

        foreach ($this->db->table('groups')->where('id', '>', 4)->pluck('id') as $gid) {
            $grants[(int) $gid] = $member;
        }

        $inserted = 0;

        foreach ($grants as $gid => $permissions) {
            if (! $this->db->table('groups')->where('id', $gid)->exists()) {
                continue;
            }

            foreach ($permissions as $perm) {
                $exists = $this->db->table('group_permission')
                    ->where('group_id', $gid)
                    ->where('permission', $perm)
                    ->exists();

                if (! $exists) {
                    $this->db->table('group_permission')->insert([
                        'group_id' => $gid,
                        'permission' => $perm,
                    ]);
                    $inserted++;
                }
            }
        }

        $this->info("Permissions granted: {$inserted}");
        $this->info('  groups covered: Guest(2), Member(3), Mod(4) + migrated custom groups.');

        return 0;
    }
}
