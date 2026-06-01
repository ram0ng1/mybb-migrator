<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Limpa o conteúdo da instalação Flarum atual antes da migração do MyBB.
 *
 * Trunca as tabelas de conteúdo do core (users, discussions, posts, tags,
 * pivots e tokens) e das extensões diretamente envolvidas na migração
 * (likes, mentions, reactions, byobu, polls, recipients). Os grupos do
 * core (1..4: Admin/Guest/Member/Mod) são preservados; grupos customizados
 * (id > 4) são apagados.
 *
 * Exige --force para evitar execução acidental. As FKs são desabilitadas
 * durante o processo; ao final são reabilitadas.
 */
class WipeCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:wipe')
            ->setDescription('Wipe the current Flarum content (keeps schema, core groups and settings).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the interactive confirmation.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Destructive command. Run with --force to confirm.');
            return 1;
        }

        $tables = [
            'mybb_legacy_passwords',
            'mail_reply_posts', 'scheduled_posts',
            'post_mentions_user', 'post_mentions_post', 'post_mentions_tag', 'post_mentions_group',
            'post_likes', 'post_reactions', 'post_anonymous_reactions',
            'post_user', 'flags',
            'recipients',
            'poll_votes', 'poll_options', 'poll_groups', 'polls',
            'posts',
            'discussion_tag', 'discussion_user', 'tag_user',
            'discussions', 'tags',
            'notifications',
            'access_tokens', 'password_tokens', 'email_tokens', 'registration_tokens', 'unsubscribe_tokens', 'login_providers',
            'group_user',
            'users',
        ];

        $this->info('Disabling FKs and truncating content tables...');
        $this->db->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($tables as $table) {
                if ($this->db->getSchemaBuilder()->hasTable($table)) {
                    $this->db->table($table)->truncate();
                    $this->info("  truncated: $table");
                }
            }

            $deletedGroups = $this->db->table('groups')->where('id', '>', 4)->delete();
            $this->info("  deleted groups (id>4): $deletedGroups");

            $orphanPerms = $this->db->table('group_permission')
                ->where('permission', 'LIKE', 'tag%')
                ->orWhere(function ($q) {
                    $q->where('permission', 'LIKE', 'discussion%')->where('permission', 'NOT LIKE', 'discussion.start')
                      ->where('permission', 'NOT LIKE', 'discussion.reply')->where('permission', 'NOT LIKE', 'discussion.viewForum');
                })
                ->delete();
            $this->info("  cleaned tag/discussion-scoped permissions: $orphanPerms");
        } finally {
            $this->db->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->info('Cleanup complete.');
        return 0;
    }
}
