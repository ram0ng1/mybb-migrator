<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Adiciona um usuário (default ramon) ao grupo Admin (id=1) para inspeção
 * pós-migração. A busca é case-insensitive e suporta correspondência parcial
 * via --like (ex.: ramon casa com ram0ng1, ramonguilherme, etc.).
 */
class MakeAdminCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:make-admin')
            ->setDescription('Promotes the chosen user (default ramon) to the Admin group.')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Username (default: ramon).', 'ramon')
            ->addOption('like', null, InputOption::VALUE_NONE, 'Uses LIKE %username% for partial matching (case-insensitive).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Run with --force.');
            return 1;
        }

        $username = (string) $this->input->getOption('username');
        $like = (bool) $this->input->getOption('like');

        $query = $this->db->table('users')->select('id', 'username');

        if ($like) {
            $query->whereRaw('LOWER(username) LIKE ?', ['%' . strtolower($username) . '%']);
        } else {
            $query->whereRaw('LOWER(username) = ?', [strtolower($username)]);
        }

        $users = $query->orderBy('id')->limit(10)->get();

        if ($users->isEmpty()) {
            $this->error("No user found for '{$username}'.");
            return 1;
        }

        if ($users->count() > 1) {
            $this->info('Multiple candidates found:');
            foreach ($users as $u) {
                $this->info("  id={$u->id}  username={$u->username}");
            }
            $this->info('Use an exact --username (without --like) to choose one.');
            return 1;
        }

        $user = $users->first();

        $already = $this->db->table('group_user')
            ->where('user_id', $user->id)
            ->where('group_id', 1)
            ->exists();

        if (! $already) {
            $this->db->table('group_user')->insert([
                'user_id' => $user->id,
                'group_id' => 1,
            ]);
        }

        $this->info("✓ {$user->username} (id={$user->id}) is now Admin" . ($already ? ' (already was).' : '.'));

        return 0;
    }
}
