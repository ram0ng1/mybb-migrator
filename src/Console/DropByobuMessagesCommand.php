<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Discussion\Discussion;
use Flarum\Notification\Notification;
use Flarum\Post\Post;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Apaga as discussões privadas do fof/byobu que a versão anterior de
 * `mybb:messages` criou a partir das PMs do MyBB — agora que as PMs vão para o
 * flarum/messages, elas são uma segunda cópia do mesmo histórico.
 *
 * Por que um comando e não "desinstalar o byobu": o byobu NÃO apaga esses dados
 * ao sair. Desativar não mexe em nada; desinstalar (purge) roda os `down` das
 * migrações dele, que só derrubam a tabela `recipients` e a coluna
 * `users.blocks_byobu_pd`. As discussões (is_private=1), os posts, o
 * discussion_user e as notificações ficam no banco — invisíveis, porque o core
 * esconde is_private=1 de todo mundo quando nenhuma extensão as libera. Este
 * comando funciona antes ou depois de remover o byobu.
 *
 * O que é apagado, e só isso:
 *  - por padrão, discussões is_private=1 com a assinatura da migração antiga:
 *    slug igual ao próprio id (o comando antigo gravava slug = id; discussões
 *    criadas no Flarum ganham slug a partir do título);
 *  - com --include-native, também as discussões privadas do byobu criadas por
 *    membros no Flarum (is_private=1 com linhas em recipients).
 * Discussão pública (is_private=0) nunca entra, em nenhum modo.
 *
 * Junto com cada discussão vai tudo que pendura nela e nos seus posts:
 * recipients, discussion_user, tags, favoritos, notificações (as de assunto
 * discussão e as de assunto post), menções, curtidas, reações, flags e vínculos
 * de upload. Os filhos são apagados explicitamente, sem desligar as FKs — o que
 * não está na lista e tem FK com CASCADE/SET NULL é tratado pelo próprio banco.
 */
class DropByobuMessagesCommand extends AbstractCommand
{
    private const CHUNK = 200;

    /** Tabelas que referenciam posts: tabela => colunas com id de post. */
    private const POST_CHILDREN = [
        'post_mentions_post'           => ['post_id', 'mentions_post_id'],
        'post_mentions_user'           => ['post_id'],
        'post_mentions_group'          => ['post_id'],
        'post_mentions_tag'            => ['post_id'],
        'post_likes'                   => ['post_id'],
        'post_reactions'               => ['post_id'],
        'post_anonymous_reactions'     => ['post_id'],
        'dfs_post_reactions'           => ['post_id'],
        'post_user'                    => ['post_id'],
        'flags'                        => ['post_id'],
        'fof_upload_file_posts'        => ['post_id'],
        'dialog_message_mentions_post' => ['mentions_post_id'],
    ];

    /** Tabelas que referenciam discussões e cujas linhas somem junto. */
    private const DISCUSSION_CHILDREN = [
        'recipients', 'discussion_user', 'discussion_tag', 'discussion_sticky_tag',
        'dfs_bookmarks', 'dfs_discussion_heroes',
    ];

    /** Referências opcionais (ON DELETE SET NULL): tabela => [coluna, tipo]. */
    private const NULLABLE_REFERENCES = [
        ['fof_upload_downloads', 'post_id', 'post'],
        ['fof_upload_downloads', 'discussion_id', 'discussion'],
        ['tfb_feedbacks', 'discussion_id', 'discussion'],
        ['tags', 'last_posted_discussion_id', 'discussion'],
    ];

    /** @var array<string, bool> */
    private array $hasTableCache = [];

    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:messages-drop-byobu')
            ->setDescription('Delete the fof/byobu private discussions created from MyBB PMs by the old mybb:messages (never public discussions).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm the destructive execution.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count what would be deleted.')
            ->addOption('include-native', null, InputOption::VALUE_NONE, 'Also delete byobu private discussions members created in Flarum (is_private=1 with recipients).');
    }

    protected function fire(): int
    {
        $dryRun = (bool) $this->input->getOption('dry-run');
        $native = (bool) $this->input->getOption('include-native');

        if (! $dryRun && ! $this->input->getOption('force')) {
            $this->error('Destructive command. Run with --force (or --dry-run to only count).');
            return 1;
        }

        if ($native && ! $this->hasTable('recipients')) {
            $this->error('--include-native needs the recipients table (fof/byobu already purged?). Without it only migrated discussions can be identified.');
            return 1;
        }

        $ids = $this->targetDiscussionIds($native);
        $this->info('[mybb:messages-drop-byobu] Private discussions selected: ' . count($ids) . ($native ? ' (migrated + native)' : ' (migrated from MyBB only)'));

        [$discussionTypes, $postTypes] = $this->notificationTypes();

        $totals = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $counts = $dryRun ? $this->countChunk($chunk, $discussionTypes, $postTypes) : $this->deleteChunk($chunk, $discussionTypes, $postTypes);
            foreach ($counts as $key => $value) {
                $totals[$key] = ($totals[$key] ?? 0) + $value;
            }
        }

        $this->info($dryRun ? '[mybb:messages-drop-byobu] DRY-RUN — nothing deleted. Would delete:' : '[mybb:messages-drop-byobu] Done. Deleted:');
        $this->info('  discussions: ' . count($ids));
        ksort($totals);
        foreach ($totals as $key => $value) {
            $this->info("  $key: $value");
        }

        $public = (int) $this->db->table('discussions')->where('is_private', 0)->count();
        $this->info("  public discussions (untouched): $public");

        return 0;
    }

    /**
     * @return list<int>
     */
    private function targetDiscussionIds(bool $native): array
    {
        $query = $this->db->table('discussions')
            ->where('is_private', 1)
            ->where(function ($q) use ($native) {
                // Assinatura do mybb:messages antigo: slug = id.
                $q->whereRaw('slug = CAST(id AS CHAR)');

                if ($native) {
                    $q->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('recipients')
                        ->whereColumn('recipients.discussion_id', 'discussions.id'));
                }
            })
            ->orderBy('id');

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Tipos de notificação cujo assunto é uma discussão e os cujo assunto é um
     * post, segundo os blueprints registrados (o byobu tem os seus, o core e as
     * outras extensões os delas). A tabela notifications não tem FK para o
     * assunto, então sem isto elas ficariam apontando para nada.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function notificationTypes(): array
    {
        $discussion = [];
        $post = [];

        foreach (Notification::getSubjectModels() as $type => $model) {
            if (is_a($model, Discussion::class, true)) {
                $discussion[] = $type;
            } elseif (is_a($model, Post::class, true)) {
                $post[] = $type;
            }
        }

        // Os do byobu, mesmo que ele já tenha sido desativado/removido.
        foreach (['byobuPrivateDiscussionCreated', 'byobuPrivateDiscussionAdded', 'byobuPrivateDiscussionReplied', 'byobuRecipientRemoved', 'byobuMadePublic'] as $type) {
            if (! in_array($type, $discussion, true)) {
                $discussion[] = $type;
            }
        }

        return [$discussion, $post];
    }

    /**
     * @param list<int> $discussionIds
     * @param list<string> $discussionTypes
     * @param list<string> $postTypes
     * @return array<string, int>
     */
    private function countChunk(array $discussionIds, array $discussionTypes, array $postTypes): array
    {
        $postIds = $this->postIds($discussionIds);
        $counts = ['posts' => count($postIds)];

        $counts['notifications'] = $this->notificationsQuery($discussionIds, $postIds, $discussionTypes, $postTypes)?->count() ?? 0;

        foreach ($this->childQueries($discussionIds, $postIds) as $table => $query) {
            $counts[$table] = ($counts[$table] ?? 0) + $query->count();
        }

        return array_filter($counts);
    }

    /**
     * @param list<int> $discussionIds
     * @param list<string> $discussionTypes
     * @param list<string> $postTypes
     * @return array<string, int>
     */
    private function deleteChunk(array $discussionIds, array $discussionTypes, array $postTypes): array
    {
        $counts = [];

        $this->db->transaction(function () use ($discussionIds, $discussionTypes, $postTypes, &$counts): void {
            $postIds = $this->postIds($discussionIds);

            $counts['notifications'] = $this->notificationsQuery($discussionIds, $postIds, $discussionTypes, $postTypes)?->delete() ?? 0;

            foreach ($this->childQueries($discussionIds, $postIds) as $table => $query) {
                $counts[$table] = ($counts[$table] ?? 0) + $query->delete();
            }

            foreach (self::NULLABLE_REFERENCES as [$table, $column, $kind]) {
                $ids = $kind === 'post' ? $postIds : $discussionIds;
                if ($ids !== [] && $this->hasColumn($table, $column)) {
                    $this->db->table($table)->whereIn($column, $ids)->update([$column => null]);
                }
            }

            // Solta os ponteiros first/last_post antes de apagar os posts.
            $this->db->table('discussions')->whereIn('id', $discussionIds)
                ->update(['first_post_id' => null, 'last_post_id' => null]);

            foreach (array_chunk($postIds, 1000) as $chunk) {
                $counts['posts'] = ($counts['posts'] ?? 0) + $this->db->table('posts')->whereIn('id', $chunk)->delete();
            }

            // Cinto e suspensório: o WHERE repete is_private=1, então nem um id
            // trocado por engano apagaria uma discussão pública.
            $this->db->table('discussions')->whereIn('id', $discussionIds)->where('is_private', 1)->delete();
        });

        return array_filter($counts);
    }

    /**
     * @param list<int> $discussionIds
     * @return list<int>
     */
    private function postIds(array $discussionIds): array
    {
        return $this->db->table('posts')->whereIn('discussion_id', $discussionIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param list<int> $discussionIds
     * @param list<int> $postIds
     * @param list<string> $discussionTypes
     * @param list<string> $postTypes
     */
    private function notificationsQuery(array $discussionIds, array $postIds, array $discussionTypes, array $postTypes): ?\Illuminate\Database\Query\Builder
    {
        if (! $this->hasTable('notifications')) {
            return null;
        }

        return $this->db->table('notifications')->where(function ($q) use ($discussionIds, $postIds, $discussionTypes, $postTypes) {
            $q->where(fn ($q) => $q->whereIn('type', $discussionTypes)->whereIn('subject_id', $discussionIds));

            if ($postIds !== [] && $postTypes !== []) {
                $q->orWhere(fn ($q) => $q->whereIn('type', $postTypes)->whereIn('subject_id', $postIds));
            }
        });
    }

    /**
     * Uma query por tabela-filha existente, na ordem segura de apagar (filhos
     * dos posts antes dos filhos das discussões).
     *
     * @param list<int> $discussionIds
     * @param list<int> $postIds
     * @return iterable<string, \Illuminate\Database\Query\Builder>
     */
    private function childQueries(array $discussionIds, array $postIds): iterable
    {
        if ($postIds !== []) {
            foreach (self::POST_CHILDREN as $table => $columns) {
                if (! $this->hasTable($table)) {
                    continue;
                }
                $columns = array_values(array_filter($columns, fn ($column) => $this->hasColumn($table, $column)));
                if ($columns === []) {
                    continue;
                }

                yield $table => $this->db->table($table)->where(function ($q) use ($columns, $postIds) {
                    foreach ($columns as $column) {
                        $q->orWhereIn($column, $postIds);
                    }
                });
            }
        }

        foreach (self::DISCUSSION_CHILDREN as $table) {
            if ($this->hasColumn($table, 'discussion_id')) {
                yield $table => $this->db->table($table)->whereIn('discussion_id', $discussionIds);
            }
        }
    }

    private function hasTable(string $table): bool
    {
        return $this->hasTableCache[$table] ??= $this->db->getSchemaBuilder()->hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->hasTable($table) && ($this->hasTableCache["$table.$column"] ??= $this->db->getSchemaBuilder()->hasColumn($table, $column));
    }
}
