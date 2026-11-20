<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Formatter\Formatter;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Ramon\MybbMigrator\BBCode\Converter;
use Ramon\MybbMigrator\Support\Charset;
use Ramon\MybbMigrator\Support\ReviewMath;
use Symfony\Component\Console\Input\InputOption;

/**
 * Migra o plugin "Community Reviews" do MyBB (reviews de produtos) para a
 * extensão ernestdefoe/community-reviews, preservando os IDs de origem.
 *
 * Mapeamento (MyBB → community-reviews):
 *   community_reviews_categories    → community_review_categories (slug único como o admin da extensão gera)
 *   community_reviews_fields        → community_review_criteria   (peso 1 = média simples do MyBB)
 *   community_reviews_products      → community_review_items      (views → view_count; slug = Str::slug(nome))
 *   community_reviews               → community_reviews           (comment → body, BBCode convertido e parseado
 *                                                                  pelo formatter do Flarum, como a extensão faz)
 *   community_reviews_merchants     → community_reviews.merchant_id
 *   community_reviews_review_fields → community_review_scores     (rating 1-5 → score; comment → comment)
 *   community_reviews_photos        → community_review_photos     (link mantido, ou baixado: ver --photos)
 *   community_reviews_comments      → community_review_comments   (comment → body formatado)
 *
 * Ordem FK-segura, sem desligar as FKs: um filho só entra se o pai existe.
 * Autor ausente no Flarum vira NULL (todas essas colunas são nullable com
 * nullOnDelete), exceto em fotos, cujo `user_id` é obrigatório: ali a foto
 * fica com o autor da review, ou com --photo-owner, ou é pulada (e contada).
 *
 * Idempotente: tudo entra com insertOrIgnore pelo ID de origem; rodar de novo
 * não duplica nem sobrescreve o que já foi editado no Flarum. Os campos
 * derivados (rating da review, comment_count, e no item rating/rating_rank/
 * criteria_avg/distribution/thumbnail/contagens, e na categoria item_count/
 * review_count) são SEMPRE recalculados no fim — os do item e da categoria pelo
 * próprio ErnestDefoe\CommunityReviews\Aggregates da extensão, o mesmo que o
 * job RefreshItem dela executa a cada review salva.
 *
 * Modo à parte, --purge-legacy: apaga as linhas que a versão anterior deste
 * comando gravava em tabelas tfb_* de reviews (só as que existirem). Nunca toca
 * no trader feedback de verdade (tfb_feedbacks, tfb_stats, tfb_reports).
 */
class MigrateReviewsCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    private const BATCH = 1000;

    /**
     * Tabelas de reviews que a versão antiga deste comando gravava (um fork do
     * huseyinfiliz/traderfeedback). Ordem de filhos para pais, para o DELETE
     * não esbarrar em FK. As tabelas do trader feedback propriamente dito
     * (tfb_feedbacks, tfb_stats, tfb_reports) ficam de fora DE PROPÓSITO.
     */
    private const LEGACY_TABLES = [
        'tfb_review_comments',
        'tfb_review_photos',
        'tfb_review_field_ratings',
        'tfb_product_reviews',
        'tfb_products',
        'tfb_review_fields',
        'tfb_review_categories',
    ];

    private bool $dryRun = false;

    /** Textos que o formatter recusou e foram guardados como texto plano. */
    private int $parseFailures = 0;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected Formatter $formatter,
        protected Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:reviews')
            ->setDescription('Migrate MyBB Community Reviews to ernestdefoe/community-reviews.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and convert everything, report the counts, write nothing.')
            ->addOption('photos', null, InputOption::VALUE_REQUIRED, 'Photos: "link" keeps the MyBB URLs (imgur) as links, "download" stores them on the extension\'s disk (falls back to the link when a download fails), "skip" ignores them.', 'link')
            ->addOption('photo-base-url', null, InputOption::VALUE_REQUIRED, 'Base URL for photo paths stored relative to the MyBB site (default: the panel\'s old site URL). Relative photos are skipped without it.')
            ->addOption('photo-owner', null, InputOption::VALUE_REQUIRED, 'Flarum user id that owns photos whose reviewer is not in Flarum (photos require an owner). Without it those photos are skipped.')
            ->addOption('insecure', null, InputOption::VALUE_NONE, 'With --photos=download: skip TLS certificate verification.')
            ->addOption('purge-legacy', null, InputOption::VALUE_NONE, 'Only delete what the previous version of this command wrote to tfb_* review tables (never the trader feedback). Use with --dry-run first.');
        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        $this->dryRun = (bool) $this->input->getOption('dry-run');

        if (! $this->dryRun && ! $this->input->getOption('force')) {
            $this->error('Run with --force (or --dry-run to preview).');
            return 1;
        }

        if ($this->input->getOption('purge-legacy')) {
            return $this->purgeLegacy();
        }

        $schema = $this->db->getSchemaBuilder();
        if (! $schema->hasTable('community_review_items') || ! $schema->hasTable('community_review_scores')) {
            $this->error('Community Reviews tables do not exist. Install and enable ernestdefoe/community-reviews (php flarum migrate) first.');
            return 1;
        }

        $photoMode = (string) $this->input->getOption('photos');
        if (! in_array($photoMode, ['link', 'download', 'skip'], true)) {
            $this->error('--photos must be link, download or skip.');
            return 1;
        }

        $mybb = $this->buildMybbDatabase($this->settings);
        $p = $mybb->prefix();

        $userIds = $this->loadIdSet('users');
        $this->info('Users in Flarum: ' . count($userIds) . ($this->dryRun ? '  [dry-run: nothing will be written]' : ''));

        // 1) Categorias → categorias
        $cats = [];
        $existing = $this->loadIdSet('community_review_categories');
        $taken = [];
        foreach ($this->db->table('community_review_categories')->select('id', 'slug')->cursor() as $row) {
            $taken[(string) $row->slug] = true;
        }
        $new = 0;
        $batch = [];
        foreach ($mybb->cursor("SELECT id, name, `order` FROM {$p}community_reviews_categories ORDER BY id") as $r) {
            $id = (int) $r['id'];
            $cats[$id] = true;
            if (isset($existing[$id])) {
                continue;
            }
            $name = $this->clip(Charset::fix($r['name']), 100) ?? ('Category ' . $id);
            $slug = ReviewMath::uniqueSlug($name, $taken, fn (string $n): string => Str::slug($n));
            $taken[$slug] = true;
            $new++;
            $batch[] = [
                'id' => $id,
                'parent_id' => null,
                'name' => $name,
                'slug' => $slug,
                'position' => max(0, (int) $r['order']),
                'item_count' => 0,
                'review_count' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }
        $this->flush('community_review_categories', $batch);
        $this->report('Categories', count($cats), $new);

        // 2) Campos → critérios
        $criteria = []; // id => category_id
        $existing = $this->loadIdSet('community_review_criteria');
        $new = 0;
        $batch = [];
        foreach ($mybb->cursor("SELECT id, category_id, name, `order` FROM {$p}community_reviews_fields ORDER BY id") as $r) {
            $id = (int) $r['id'];
            $catId = (int) $r['category_id'];
            if (! isset($cats[$catId])) {
                continue;
            }
            $criteria[$id] = $catId;
            if (isset($existing[$id])) {
                continue;
            }
            $new++;
            $batch[] = [
                'id' => $id,
                'category_id' => $catId,
                'name' => $this->clip(Charset::fix($r['name']), 100) ?? ('Criterion ' . $id),
                'weight' => 1,
                'position' => max(0, (int) $r['order']),
            ];
        }
        $this->flush('community_review_criteria', $batch);
        $this->report('Criteria', count($criteria), $new);

        // 3) Produtos → items
        $items = []; // id => category_id
        $existing = $this->loadIdSet('community_review_items');
        $new = 0;
        $batch = [];
        foreach ($mybb->cursor("SELECT id, category_id, name, date, user_id, views FROM {$p}community_reviews_products ORDER BY id") as $r) {
            $id = (int) $r['id'];
            $catId = (int) $r['category_id'];
            if (! isset($cats[$catId])) {
                continue;
            }
            $items[$id] = $catId;
            if (isset($existing[$id])) {
                continue;
            }
            $name = $this->clip(Charset::fix($r['name']), 255) ?? ('Item ' . $id);
            $date = $this->ts((int) $r['date']);
            $new++;
            $batch[] = [
                'id' => $id,
                'category_id' => $catId,
                'user_id' => $this->userOrNull((int) $r['user_id'], $userIds),
                'name' => $name,
                'slug' => mb_substr(Str::slug($name) ?: 'item', 0, 255),
                'status' => 'approved',
                'view_count' => max(0, (int) $r['views']),
                'last_activity_at' => $date,
                'created_at' => $date,
                'updated_at' => $date,
            ];
            $this->flushIfFull('community_review_items', $batch);
        }
        $this->flush('community_review_items', $batch);
        $this->report('Items', count($items), $new);

        // Lojista marcado na review (MyBB: tabela própria review_id → user_id)
        $merchants = [];
        foreach ($mybb->cursor("SELECT review_id, user_id FROM {$p}community_reviews_merchants") as $r) {
            $merchants[(int) $r['review_id']] = (int) $r['user_id'];
        }

        // 4) Reviews
        $reviews = []; // id => [item_id, user_id|null]
        $existing = $this->loadIdSet('community_reviews');
        $new = 0;
        $orphanAuthors = 0;
        $batch = [];
        foreach ($mybb->cursor("SELECT id, product_id, user_id, date, price, url, comment FROM {$p}community_reviews ORDER BY id") as $r) {
            $id = (int) $r['id'];
            $itemId = (int) $r['product_id'];
            if (! isset($items[$itemId])) {
                continue;
            }
            $userId = $this->userOrNull((int) $r['user_id'], $userIds);
            $reviews[$id] = [$itemId, $userId];
            if (isset($existing[$id])) {
                continue;
            }
            if ($userId === null) {
                $orphanAuthors++;
            }
            $date = $this->ts((int) $r['date']);
            $new++;
            $batch[] = [
                'id' => $id,
                'item_id' => $itemId,
                'user_id' => $userId,
                'rating' => 0, // recalculado no passo final, a partir das notas
                'title' => null, // o MyBB não tem título de review
                'body' => $this->formatBody((string) ($r['comment'] ?? ''), 'review', $id, true),
                'price' => $this->clip(Charset::fix($r['price']), 30),
                'url' => $this->clip((string) $r['url'], 255),
                'merchant_id' => $this->userOrNull($merchants[$id] ?? 0, $userIds),
                'status' => 'approved', // o plugin do MyBB não tem fila de aprovação
                'created_at' => $date,
                'updated_at' => $date,
            ];
            $this->flushIfFull('community_reviews', $batch);
        }
        $this->flush('community_reviews', $batch);
        $this->report('Reviews', count($reviews), $new);
        if ($orphanAuthors > 0) {
            $this->info("  reviews whose author is not in Flarum (kept, author NULL): {$orphanAuthors}");
        }

        // 5) Notas por campo → scores
        $existing = $this->loadIdSet('community_review_scores');
        $seen = $this->loadScorePairs();
        $total = 0;
        $new = 0;
        $skippedEmpty = 0;
        $skippedCategory = 0;
        $batch = [];
        foreach ($mybb->cursor("SELECT id, review_id, field_id, comment, rating FROM {$p}community_reviews_review_fields ORDER BY id") as $r) {
            $id = (int) $r['id'];
            $reviewId = (int) $r['review_id'];
            $criterionId = (int) $r['field_id'];
            if (! isset($reviews[$reviewId], $criteria[$criterionId])) {
                continue;
            }
            // A extensão só aceita (e só exibe) critérios da categoria do item.
            if ($criteria[$criterionId] !== $items[$reviews[$reviewId][0]]) {
                $skippedCategory++;
                continue;
            }
            $score = ReviewMath::score($r['rating']);
            if ($score === null) {
                $skippedEmpty++;
                continue;
            }
            $total++;
            $pair = $reviewId . ':' . $criterionId;
            if (isset($existing[$id]) || isset($seen[$pair])) {
                continue;
            }
            $seen[$pair] = true;
            $new++;
            $batch[] = [
                'id' => $id,
                'review_id' => $reviewId,
                'criterion_id' => $criterionId,
                'score' => $score,
                'comment' => $this->clip(Charset::fix($r['comment']), 65535),
            ];
            $this->flushIfFull('community_review_scores', $batch);
        }
        $this->flush('community_review_scores', $batch);
        $this->report('Scores', $total, $new);
        if ($skippedEmpty > 0) {
            $this->info("  scores skipped (no rating, 0): {$skippedEmpty}");
        }
        if ($skippedCategory > 0) {
            $this->info("  scores skipped (criterion of another category than the item's): {$skippedCategory}");
        }

        // 6) Fotos
        if ($photoMode === 'skip') {
            $this->info('Photos: skipped (--photos=skip)');
        } else {
            $this->migratePhotos($mybb, $p, $reviews, $userIds, $photoMode);
        }

        // 7) Comentários
        $existing = $this->loadIdSet('community_review_comments');
        $total = 0;
        $new = 0;
        $batch = [];
        foreach ($mybb->cursor("SELECT id, product_id, review_id, user_id, date, comment FROM {$p}community_reviews_comments ORDER BY id") as $r) {
            $id = (int) $r['id'];
            $itemId = (int) $r['product_id'];
            if (! isset($items[$itemId])) {
                continue;
            }
            $total++;
            if (isset($existing[$id])) {
                continue;
            }
            // Resposta a uma review só vale se a review for do MESMO item
            // (é a regra do CommentWriter::replyTo da extensão).
            $reviewId = (int) ($r['review_id'] ?? 0);
            $replyTo = isset($reviews[$reviewId]) && $reviews[$reviewId][0] === $itemId ? $reviewId : null;
            $date = $this->ts((int) $r['date']);
            $new++;
            $batch[] = [
                'id' => $id,
                'item_id' => $itemId,
                'review_id' => $replyTo,
                'user_id' => $this->userOrNull((int) $r['user_id'], $userIds),
                'body' => $this->formatBody((string) ($r['comment'] ?? ''), 'comment', $id, false),
                'is_official' => false,
                'created_at' => $date,
                'updated_at' => $date,
            ];
            $this->flushIfFull('community_review_comments', $batch);
        }
        $this->flush('community_review_comments', $batch);
        $this->report('Comments', $total, $new);

        if ($this->parseFailures > 0) {
            $this->error("  texts preserved as plain text (formatter failed): {$this->parseFailures}");
        }

        if ($this->dryRun) {
            $this->info('Dry-run: nothing written, derived fields not recomputed.');
            return 0;
        }

        $this->recompute(array_keys($reviews), array_keys($items), array_keys($cats));
        $this->info('Done.');

        return 0;
    }

    /**
     * Fotos. `link` grava a URL do MyBB em `url`/`thumb_url` (a extensão exibe
     * esse campo como veio: é o caminho das fotos do imgur, que é o host do
     * plugin do MyBB). `download` baixa com o ImageFetcher do migrador e passa
     * os bytes pelo Photo\Uploader DA EXTENSÃO (o mesmo de um upload pelo
     * fórum: checa tipo pelos bytes, reencoda em WebP, gera miniatura, nome
     * aleatório no disco `ernestdefoe-community-reviews`); se o download ou o
     * Uploader falhar, a foto entra como link para nada se perder.
     *
     * @param array<int, array{0: int, 1: int|null}> $reviews
     * @param array<int, bool> $userIds
     */
    private function migratePhotos(\Ramon\MybbMigrator\MybbDatabase $mybb, string $p, array $reviews, array $userIds, string $mode): void
    {
        $base = trim((string) ($this->input->getOption('photo-base-url')
            ?: ($this->settings->get('mybb-migrator.old_site_url') ?? '')));
        $ownerOpt = (int) ($this->input->getOption('photo-owner') ?? 0);
        $fallbackOwner = $ownerOpt > 0 && isset($userIds[$ownerOpt]) ? $ownerOpt : null;
        if ($ownerOpt > 0 && $fallbackOwner === null) {
            $this->error("  --photo-owner={$ownerOpt} is not a Flarum user id; ignoring it.");
        }

        $fetcher = null;
        $uploader = null;
        if ($mode === 'download' && ! $this->dryRun) {
            $fetcher = new \Ramon\MybbMigrator\Support\ImageFetcher(20, 10 * 1024 * 1024, 3, 250, null, ! $this->input->getOption('insecure'));
            $uploader = $this->container->make(\ErnestDefoe\CommunityReviews\Photo\Uploader::class);
        }

        $existing = $this->loadIdSet('community_review_photos');
        $reviewDates = [];
        $total = $new = $downloaded = $fellBack = $noOwner = $noUrl = 0;
        $batch = [];
        foreach ($mybb->cursor("SELECT id, review_id, url, thumbnail_url, `order` FROM {$p}community_reviews_photos ORDER BY review_id, `order`, id") as $r) {
            $id = (int) $r['id'];
            $reviewId = (int) $r['review_id'];
            if (! isset($reviews[$reviewId])) {
                continue;
            }
            $total++;
            if (isset($existing[$id])) {
                continue;
            }
            $owner = $reviews[$reviewId][1] ?? $fallbackOwner;
            if ($owner === null) {
                $noOwner++;
                continue;
            }
            $url = ReviewMath::photoUrl($r['url'], $base);
            if ($url === null || mb_strlen($url) > 255) {
                $noUrl++;
                continue;
            }
            $thumb = ReviewMath::photoUrl($r['thumbnail_url'], $base);
            $reviewDates[$reviewId] ??= (string) $this->db->table('community_reviews')->where('id', $reviewId)->value('created_at');
            $row = [
                'id' => $id,
                'review_id' => $reviewId,
                'user_id' => $owner,
                'path' => null,
                'thumb_path' => null,
                'url' => $url,
                'thumb_url' => $thumb !== null && mb_strlen($thumb) <= 255 ? $thumb : null,
                'width' => 0,
                'height' => 0,
                'position' => max(0, (int) ($r['order'] ?? 0)),
                'created_at' => $reviewDates[$reviewId] ?: date('Y-m-d H:i:s'),
            ];
            $new++;

            if ($fetcher !== null && $uploader !== null) {
                if ($this->downloadPhoto($fetcher, $uploader, $row)) {
                    $downloaded++;
                    continue;
                }
                $fellBack++;
            }

            $batch[] = $row;
            $this->flushIfFull('community_review_photos', $batch);
        }
        $this->flush('community_review_photos', $batch);
        $this->report('Photos', $total, $new, $noOwner + $noUrl);
        if ($mode === 'download' && ! $this->dryRun) {
            $this->info("  downloaded to the extension's disk: {$downloaded}; kept as links (download failed): {$fellBack}");
        }
        if ($noOwner > 0) {
            $this->info("  photos skipped (reviewer not in Flarum, no --photo-owner): {$noOwner}");
        }
        if ($noUrl > 0) {
            $this->info("  photos skipped (empty/too long URL, or relative path without --photo-base-url): {$noUrl}");
        }
    }

    /**
     * Baixa uma foto e a grava pelo Uploader da extensão; depois põe na linha
     * criada o ID de origem, a review, a posição e a data. Trocar o ID é seguro
     * aqui: a linha acabou de nascer (auto-increment > tudo que existe) e nada
     * aponta para ela ainda — o thumbnail do item só é calculado no fim.
     *
     * @param array<string, mixed> $row
     */
    private function downloadPhoto(\Ramon\MybbMigrator\Support\ImageFetcher $fetcher, object $uploader, array $row): bool
    {
        $res = $fetcher->fetchImage((string) $row['url']);
        if (! $res['ok'] || $res['bytes'] === null) {
            $this->info("  photo {$row['id']}: download failed ({$res['error']}); keeping the link.");
            return false;
        }

        $user = User::query()->find($row['user_id']);
        if ($user === null) {
            return false;
        }

        $stream = new \Laminas\Diactoros\Stream('php://temp', 'w+b');
        $stream->write((string) $res['bytes']);
        $stream->rewind();
        $file = new \Laminas\Diactoros\UploadedFile($stream, strlen((string) $res['bytes']), UPLOAD_ERR_OK, 'mybb-' . $row['id']);

        try {
            $photo = $uploader->upload($user, $file);
        } catch (\Throwable $e) {
            $this->info("  photo {$row['id']}: rejected by the extension ({$e->getMessage()}); keeping the link.");
            return false;
        }

        $this->db->table('community_review_photos')->where('id', $photo->id)->update([
            'id' => $row['id'],
            'review_id' => $row['review_id'],
            'position' => $row['position'],
            'created_at' => $row['created_at'],
        ]);

        return true;
    }

    /**
     * BBCode do MyBB → XML do formatter, exatamente como a extensão guarda
     * (Review::setBodySource / Comment::setBodySource: o MESMO formatter dos
     * posts, com o model como contexto). Falha do parser → texto plano, sem
     * perder o conteúdo (mesma estratégia do mybb:content).
     */
    private function formatBody(string $raw, string $kind, int $id, bool $nullable): ?string
    {
        $text = trim(Converter::convert($raw));
        if ($text === '') {
            return $nullable ? null : '<t></t>';
        }

        $context = $kind === 'review'
            ? new \ErnestDefoe\CommunityReviews\Model\Review()
            : new \ErnestDefoe\CommunityReviews\Model\Comment();

        try {
            return $this->formatter->parse($text, $context);
        } catch (\Throwable $e) {
            $this->parseFailures++;
            $this->error("  {$kind} {$id}: formatter failed ({$e->getMessage()}); keeping plain text.");
            $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';

            return '<t>' . htmlspecialchars($clean, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</t>';
        }
    }

    /**
     * Campos derivados, como a extensão os mantém:
     *  - review.rating: média ponderada das notas (ReviewWriter::rating);
     *  - review.comment_count: respostas à review (CommentWriter::recountReplies);
     *  - item e categoria: Aggregates::refreshItem/refreshCategory da própria
     *    extensão (rating, rating_rank, criteria_avg, distribution, thumbnail,
     *    review_count, comment_count, last_activity_at, item_count...).
     *
     * @param list<int> $reviewIds
     * @param list<int> $itemIds
     * @param list<int> $categoryIds
     */
    private function recompute(array $reviewIds, array $itemIds, array $categoryIds): void
    {
        $weights = [];
        foreach ($this->db->table('community_review_criteria')->select('id', 'weight')->cursor() as $c) {
            $weights[(int) $c->id] = (int) $c->weight;
        }

        $noScores = 0;
        foreach (array_chunk($reviewIds, self::BATCH) as $chunk) {
            $scores = [];
            foreach ($this->db->table('community_review_scores')->whereIn('review_id', $chunk)->select('review_id', 'criterion_id', 'score')->cursor() as $s) {
                $scores[(int) $s->review_id][(int) $s->criterion_id] = (int) $s->score;
            }
            $replies = $this->db->table('community_review_comments')->whereIn('review_id', $chunk)
                ->groupBy('review_id')->selectRaw('review_id, COUNT(*) AS n')->pluck('n', 'review_id');

            foreach ($chunk as $id) {
                if (! isset($scores[$id])) {
                    $noScores++;
                }
                $this->db->table('community_reviews')->where('id', $id)->update([
                    'rating' => ReviewMath::rating($scores[$id] ?? [], $weights),
                    'comment_count' => (int) ($replies[$id] ?? 0),
                ]);
            }
        }
        $this->info('Review ratings recomputed: ' . count($reviewIds));
        if ($noScores > 0) {
            $this->info("  reviews without any score (rating 0, shown as 1 star in the distribution): {$noScores}");
        }

        /** @var \ErnestDefoe\CommunityReviews\Aggregates $aggregates */
        $aggregates = $this->container->make(\ErnestDefoe\CommunityReviews\Aggregates::class);
        // Duas passadas: o rating_rank (média bayesiana) puxa para a média da
        // CATEGORIA, que só fica certa depois que todos os itens dela têm
        // rating. Na primeira passada os itens do fim ainda estariam NULL.
        foreach ([1, 2] as $_) {
            foreach ($itemIds as $id) {
                $aggregates->refreshItem($id);
            }
        }
        // Categoria sem item nenhum não passa pelo refreshItem.
        foreach ($categoryIds as $id) {
            $aggregates->refreshCategory($id);
        }
        $this->info('Item and category aggregates recomputed (Aggregates): ' . count($itemIds) . ' items, ' . count($categoryIds) . ' categories.');
    }

    /**
     * Apaga o que a versão anterior deste comando gravava em tabelas tfb_* de
     * reviews. Só mexe nas tabelas de LEGACY_TABLES que existirem; o trader
     * feedback (tfb_feedbacks/tfb_stats/tfb_reports) nunca é tocado.
     */
    private function purgeLegacy(): int
    {
        $schema = $this->db->getSchemaBuilder();
        $found = 0;
        foreach (self::LEGACY_TABLES as $table) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            $found++;
            $count = (int) $this->db->table($table)->count();
            if ($this->dryRun) {
                $this->info("  [dry-run] {$table}: {$count} rows would be deleted");
                continue;
            }
            $this->db->table($table)->delete();
            $this->info("  {$table}: {$count} rows deleted");
        }

        $this->info($found === 0
            ? 'No legacy tfb_* review tables found: nothing to purge.'
            : 'Trader feedback tables (tfb_feedbacks, tfb_stats, tfb_reports) were not touched.');

        return 0;
    }

    private function report(string $label, int $total, int $new, int $skipped = 0): void
    {
        $verb = $this->dryRun ? 'would be new' : 'new';
        $line = "{$label}: {$total} ({$new} {$verb}, " . ($total - $new - $skipped) . ' already present';
        $this->info($line . ($skipped > 0 ? ", {$skipped} skipped)" : ')'));
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     */
    private function flushIfFull(string $table, array &$batch): void
    {
        if (count($batch) >= self::BATCH) {
            $this->flush($table, $batch);
            $batch = [];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     */
    private function flush(string $table, array $batch): void
    {
        if ($batch !== [] && ! $this->dryRun) {
            $this->db->table($table)->insertOrIgnore($batch);
        }
    }

    /**
     * @param array<int, bool> $userIds
     */
    private function userOrNull(int $uid, array $userIds): ?int
    {
        return ($uid > 0 && isset($userIds[$uid])) ? $uid : null;
    }

    private function ts(int $unix): string
    {
        return $unix > 0 ? date('Y-m-d H:i:s', $unix) : date('Y-m-d H:i:s');
    }

    private function clip(?string $v, int $len): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        return mb_substr($v, 0, $len);
    }

    /** Pares review:critério já gravados (a tabela tem unique nesse par). @return array<string, true> */
    private function loadScorePairs(): array
    {
        $set = [];
        foreach ($this->db->table('community_review_scores')->select('review_id', 'criterion_id')->cursor() as $row) {
            $set[$row->review_id . ':' . $row->criterion_id] = true;
        }
        return $set;
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
