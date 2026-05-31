<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Formatter\Formatter;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Ramon\MybbMigrator\BBCode\Converter;
use Ramon\MybbMigrator\Support\Charset;
use Symfony\Component\Console\Input\InputOption;

/**
 * Migra tópicos e posts do MyBB para discussions e posts do Flarum.
 *
 * Pontos críticos de fidelidade:
 *  - Preserva tid como discussions.id e pid como posts.id (essencial para os
 *    passes seguintes — likes, menções, citações — referenciarem corretamente).
 *  - Converte o BBCode do MyBB para o formato do Flarum (Converter), incluindo
 *    o reescrever de quotes para @"Autor"#pPID + [quote="Autor"] e a correção
 *    de mojibake (UTF-8 duplo).
 *  - Pivot discussion_tag inclui o fid do tópico E todos os ancestrais
 *    (Flarum renderiza a hierarquia a partir do conjunto).
 *  - Extrai menções (usuário e post) do conteúdo convertido para popular
 *    post_mentions_user e post_mentions_post — habilitando "respostas" e
 *    "menções" na timeline do flarum/mentions.
 *  - Posts sticky/closed viram is_sticky/is_locked; soft-deleted (visible=-1)
 *    viram hidden_at.
 */
class MigrateContentCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    private const POST_BATCH = 200;
    private const PIVOT_BATCH = 1000;
    private const MENTION_BATCH = 2000;
    private const CHUNK = 200;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected Formatter $formatter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:content')
            ->setDescription('Migra tópicos e posts do MyBB para discussões e posts do Flarum (com conversão BBCode->Flarum e preservação de IDs).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirma execução.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Migrar no máximo N tópicos.', null)
            ->addOption('skip-soft-deleted', null, InputOption::VALUE_NONE, 'Pula posts/threads soft-deleted (visible=-1).');
        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Rode com --force.');
            return 1;
        }

        $limit = $this->input->getOption('limit') ? (int) $this->input->getOption('limit') : null;
        $skipSoft = (bool) $this->input->getOption('skip-soft-deleted');

        $mybb = $this->buildMybbDatabase($this->settings);
        $prefix = $mybb->prefix();

        $totalThreads = (int) $mybb->scalar("SELECT COUNT(*) FROM {$prefix}threads");
        $totalPosts = (int) $mybb->scalar("SELECT COUNT(*) FROM {$prefix}posts");
        $this->info("Origem: {$totalThreads} tópicos / {$totalPosts} posts" . ($limit ? " (limite={$limit} tópicos)" : ''));

        $schema = $this->db->getSchemaBuilder();
        $discussionColumns = array_flip($schema->getColumnListing('discussions'));
        $postColumns = array_flip($schema->getColumnListing('posts'));

        $userIds = $this->loadUserIdSet();
        $tagIds = $this->loadTagIdSet();
        $tagParents = $this->loadTagParentMap();
        $this->info('  usuários no Flarum: '.count($userIds).', tags: '.count($tagIds));

        $this->db->statement('SET FOREIGN_KEY_CHECKS=0');

        $this->info('  autolimpeza de conteúdo (discussions/posts/pivots)...');
        foreach (['post_mentions_user','post_mentions_post','post_mentions_tag','post_mentions_group',
                  'post_likes','post_reactions','post_anonymous_reactions','post_user','flags',
                  'mail_reply_posts','scheduled_posts','posts',
                  'discussion_tag','discussion_user','discussions'] as $t) {
            if ($this->db->getSchemaBuilder()->hasTable($t)) {
                $this->db->table($t)->truncate();
            }
        }

        $postBatch = [];
        $pivotBatch = [];
        $postMentionBatch = [];
        $userMentionBatch = [];
        $tagStats = [];

        $threadVisibleFilter = $skipSoft ? ' AND visible != -1' : '';

        $threadSql = "SELECT tid, fid, subject, uid, dateline, firstpost, lastpost,
                             lastposteruid, views, replies, closed, sticky, visible
                      FROM {$prefix}threads
                      WHERE 1=1 {$threadVisibleFilter}
                      ORDER BY tid"
                    . ($limit ? " LIMIT {$limit}" : '');

        $threadsDone = 0; $postsDone = 0; $skippedThreads = 0;

        try {
            $threadRows = $mybb->select($threadSql)->fetchAll();
            foreach ($threadRows as $trow) {
                $tid = (int) $trow['tid'];
                $fid = (int) $trow['fid'];

                if (! isset($tagIds[$fid])) {
                    $skippedThreads++;
                    continue;
                }

                $title = Charset::fix((string) $trow['subject']);
                if ($title === '') {
                    $title = "Tópico {$tid}";
                }

                $authorUid = (int) ($trow['uid'] ?? 0);
                $authorId = isset($userIds[$authorUid]) ? $authorUid : null;
                $createdAt = self::ts((int) ($trow['dateline'] ?? 0)) ?? '1970-01-01 00:00:00';
                $lastPostAt = self::ts((int) ($trow['lastpost'] ?? 0)) ?? $createdAt;
                $lastPosterUid = (int) ($trow['lastposteruid'] ?? 0);
                $lastPosterId = isset($userIds[$lastPosterUid]) ? $lastPosterUid : null;
                $hiddenAt = ((int) $trow['visible']) === -1 ? $createdAt : null;
                $isLocked = ((string) ($trow['closed'] ?? '')) === '1' ? 1 : 0;
                $isSticky = (int) ($trow['sticky'] ?? 0) > 0 ? 1 : 0;

                $discussionRow = array_intersect_key([
                    'id' => $tid,
                    'title' => mb_substr($title, 0, 200),
                    'slug' => Str::slug(mb_substr($title, 0, 80)) . '-' . $tid,
                    'comment_count' => 0,
                    'participant_count' => 0,
                    'post_number_index' => 0,
                    'created_at' => $createdAt,
                    'user_id' => $authorId,
                    'last_posted_at' => $lastPostAt,
                    'last_posted_user_id' => $lastPosterId,
                    'first_post_id' => null,
                    'last_post_id' => null,
                    'last_post_number' => null,
                    'view_count' => (int) ($trow['views'] ?? 0),
                    'hidden_at' => $hiddenAt,
                    'hidden_user_id' => null,
                    'is_private' => 0,
                    'is_approved' => $hiddenAt === null ? 1 : 0,
                    'is_locked' => $isLocked,
                    'is_sticky' => $isSticky,
                ], $discussionColumns);

                $this->db->table('discussions')->insert($discussionRow);

                $postsRows = $this->fetchAndBuildPosts($mybb, $prefix, $tid, $userIds, $skipSoft, $postColumns);

                if ($postsRows === []) {
                    $this->db->table('discussions')->where('id', $tid)->delete();
                    $skippedThreads++;
                    continue;
                }

                $firstPostId = null; $lastPostId = null; $lastNumber = 0; $participants = [];

                foreach ($postsRows as $i => $p) {
                    $postBatch[] = $p['row'];
                    if ($firstPostId === null) $firstPostId = $p['row']['id'];
                    $lastPostId = $p['row']['id'];
                    $lastNumber = $p['row']['number'];
                    if ($p['row']['user_id'] !== null) {
                        $participants[$p['row']['user_id']] = true;
                    }
                    foreach ($p['post_mentions'] as $pid) {
                        $postMentionBatch[] = ['post_id' => $p['row']['id'], 'mentions_post_id' => $pid];
                    }
                    foreach ($p['user_mentions'] as $uid) {
                        $userMentionBatch[] = ['post_id' => $p['row']['id'], 'mentions_user_id' => $uid];
                    }
                }

                $this->db->table('discussions')->where('id', $tid)->update(array_intersect_key([
                    'first_post_id' => $firstPostId,
                    'last_post_id' => $lastPostId,
                    'last_post_number' => $lastNumber,
                    'comment_count' => count($postsRows),
                    'participant_count' => count($participants),
                ], $discussionColumns));

                $pivotBatch = array_merge($pivotBatch, $this->buildTagPivot($tid, $fid, $tagParents));
                foreach ($this->ancestorChain($fid, $tagParents) as $ancestor) {
                    $tagStats[$ancestor] = ($tagStats[$ancestor] ?? 0) + 1;
                }

                $threadsDone++;
                $postsDone += count($postsRows);

                if (count($postBatch) >= self::POST_BATCH) {
                    $this->insertChunked('posts', $postBatch);
                    $postBatch = [];
                }
                if (count($pivotBatch) >= self::PIVOT_BATCH) {
                    $this->insertChunked('discussion_tag', $pivotBatch);
                    $pivotBatch = [];
                }
                if (count($postMentionBatch) >= self::MENTION_BATCH) {
                    $this->insertChunked('post_mentions_post', self::unique($postMentionBatch, ['post_id','mentions_post_id']));
                    $postMentionBatch = [];
                }
                if (count($userMentionBatch) >= self::MENTION_BATCH) {
                    $this->insertChunked('post_mentions_user', self::unique($userMentionBatch, ['post_id','mentions_user_id']));
                    $userMentionBatch = [];
                }

                if ($threadsDone % 500 === 0) {
                    $this->info("  {$threadsDone}/{$totalThreads} tópicos / {$postsDone} posts");
                }
            }

            if ($postBatch !== [])         $this->insertChunked('posts', $postBatch);
            if ($pivotBatch !== [])        $this->insertChunked('discussion_tag', $pivotBatch);
            if ($postMentionBatch !== [])  $this->insertChunked('post_mentions_post', self::unique($postMentionBatch, ['post_id','mentions_post_id']));
            if ($userMentionBatch !== [])  $this->insertChunked('post_mentions_user', self::unique($userMentionBatch, ['post_id','mentions_user_id']));

            foreach ($tagStats as $tagId => $count) {
                $this->db->table('tags')->where('id', $tagId)->update(['discussion_count' => $count]);
            }
        } finally {
            $this->db->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->info("Concluído.");
        $this->info("  discussões inseridas: {$threadsDone}");
        $this->info("  posts inseridos     : {$postsDone}");
        $this->info("  tópicos pulados (fid sem tag): {$skippedThreads}");

        return 0;
    }

    /**
     * Lê todos os posts do tópico, converte o conteúdo e produz tanto a linha
     * pronta para insert quanto a extração de menções (de usuário e de post).
     *
     * @param array<int, bool> $userIds
     * @return array<int, array{row: array<string, mixed>, post_mentions: array<int, int>, user_mentions: array<int, int>}>
     */
    private function fetchAndBuildPosts(\Ramon\MybbMigrator\MybbDatabase $mybb, string $prefix, int $tid, array $userIds, bool $skipSoft, array $postColumns): array
    {
        $filter = $skipSoft ? ' AND visible != -1' : '';
        $rows = $mybb->select(
            "SELECT pid, uid, dateline, message, visible, edituid, edittime
             FROM {$prefix}posts
             WHERE tid = ? {$filter}
             ORDER BY pid",
            [$tid]
        );

        $result = [];
        $number = 0;

        while ($prow = $rows->fetch()) {
            $pid = (int) $prow['pid'];
            $authorUid = (int) ($prow['uid'] ?? 0);
            $authorId = isset($userIds[$authorUid]) ? $authorUid : null;
            $createdAt = self::ts((int) ($prow['dateline'] ?? 0)) ?? date('Y-m-d H:i:s');
            $hidden = ((int) $prow['visible']) === -1;

            $normalized = Converter::convert((string) ($prow['message'] ?? ''));
            try {
                $content = $this->formatter->parse($normalized);
            } catch (\Throwable $e) {
                $content = $this->formatter->parse('');
            }

            $editUid = (int) ($prow['edituid'] ?? 0);
            $editTime = (int) ($prow['edittime'] ?? 0);

            $number++;
            $row = array_intersect_key([
                'id' => $pid,
                'discussion_id' => $tid,
                'number' => $number,
                'created_at' => $createdAt,
                'user_id' => $authorId,
                'type' => 'comment',
                'content' => $content,
                'edited_at' => $editTime > 0 ? self::ts($editTime) : null,
                'edited_user_id' => $editUid > 0 && isset($userIds[$editUid]) ? $editUid : null,
                'hidden_at' => $hidden ? $createdAt : null,
                'hidden_user_id' => null,
                'is_approved' => $hidden ? 0 : 1,
                'is_private' => 0,
                'ip_address' => null,
            ], $postColumns);

            $postMentions = self::extractPostMentions($normalized);
            $userMentions = self::extractUserMentions($normalized);

            $result[] = [
                'row' => $row,
                'post_mentions' => $postMentions,
                'user_mentions' => array_values(array_filter($userMentions, fn ($uid) => isset($userIds[$uid]))),
            ];
        }

        return $result;
    }

    /**
     * Devolve as linhas de discussion_tag a inserir para uma discussão: a tag
     * do fórum direto + todos os ancestrais.
     *
     * @param array<int, int> $tagParents
     * @return array<int, array<string, int>>
     */
    private function buildTagPivot(int $tid, int $fid, array $tagParents): array
    {
        $rows = [];
        foreach ($this->ancestorChain($fid, $tagParents) as $tagId) {
            $rows[] = ['discussion_id' => $tid, 'tag_id' => $tagId];
        }
        return $rows;
    }

    /**
     * Cadeia da tag até a raiz (inclui a própria).
     *
     * @param array<int, int> $tagParents
     * @return array<int, int>
     */
    private function ancestorChain(int $tagId, array $tagParents): array
    {
        $chain = [];
        $current = $tagId;
        $guard = 0;

        while ($current > 0 && $guard < 16) {
            $chain[$current] = true;
            $current = $tagParents[$current] ?? 0;
            $guard++;
        }
        return array_keys($chain);
    }

    /**
     * @return array<int, bool>
     */
    private function loadUserIdSet(): array
    {
        $set = [];
        foreach ($this->db->table('users')->select('id')->cursor() as $row) {
            $set[(int) $row->id] = true;
        }
        return $set;
    }

    /**
     * @return array<int, bool>
     */
    private function loadTagIdSet(): array
    {
        $set = [];
        foreach ($this->db->table('tags')->select('id')->cursor() as $row) {
            $set[(int) $row->id] = true;
        }
        return $set;
    }

    /**
     * @return array<int, int>
     */
    private function loadTagParentMap(): array
    {
        $map = [];
        foreach ($this->db->table('tags')->select(['id', 'parent_id'])->cursor() as $row) {
            $map[(int) $row->id] = (int) ($row->parent_id ?? 0);
        }
        return $map;
    }

    /**
     * @return array<int, int>
     */
    private static function extractPostMentions(string $content): array
    {
        if (! str_contains($content, '#p')) {
            return [];
        }

        preg_match_all('/@"[^"]*"#p(\d+)/u', $content, $m);

        $ids = array_map('intval', $m[1] ?? []);
        return array_values(array_unique(array_filter($ids, fn ($id) => $id > 0)));
    }

    /**
     * @return array<int, int>
     */
    private static function extractUserMentions(string $content): array
    {
        if (! str_contains($content, '#')) {
            return [];
        }

        preg_match_all('/@"[^"]*"#(?!p)(\d+)/u', $content, $m);

        $ids = array_map('intval', $m[1] ?? []);
        return array_values(array_unique(array_filter($ids, fn ($id) => $id > 0)));
    }

    /**
     * Insere o array em pedaços de no máximo self::CHUNK linhas por SQL para
     * não estourar o limite de placeholders do MySQL.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function insertChunked(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->db->table($table)->insert($chunk);
        }
    }

    private static function ts(int $unix): ?string
    {
        return $unix > 0 ? date('Y-m-d H:i:s', $unix) : null;
    }

    /**
     * Deduplica linhas de pivot por uma combinação de chaves.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $keys
     * @return array<int, array<string, mixed>>
     */
    private static function unique(array $rows, array $keys): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $k = '';
            foreach ($keys as $name) {
                $k .= '|'.(string) $row[$name];
            }
            if (! isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $row;
            }
        }
        return $out;
    }
}
