<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Migra o plugin "Community Reviews" do MyBB (reviews de produtos) para as
 * tabelas da extensão huseyinfiliz/traderfeedback, preservando os IDs de origem.
 *
 * Ordem FK-segura: categorias → campos → produtos → reviews → notas-por-campo →
 * fotos → comentários. Usuários ausentes no Flarum viram NULL (colunas
 * nullOnDelete); linhas cujo pai obrigatório (categoria/produto/review/campo)
 * não migrou são puladas. No fim recalcula `rating` por review (média das notas
 * de campo), `review_count` e `cached_rating` por produto.
 */
class MigrateReviewsCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    private const BATCH = 2000;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:reviews')
            ->setDescription('Migrate MyBB Community Reviews to huseyinfiliz/traderfeedback.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.');
        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Run with --force.');
            return 1;
        }

        if (! $this->db->getSchemaBuilder()->hasTable('tfb_products')) {
            $this->error('Review tables do not exist. Run `php flarum migrate` for the traderfeedback extension first.');
            return 1;
        }

        $mybb = $this->buildMybbDatabase($this->settings);
        $p = $mybb->prefix();

        $userIds = $this->loadIdSet('users');
        $this->info('Users in Flarum: ' . count($userIds));

        $this->db->getSchemaBuilder()->disableForeignKeyConstraints();

        try {
            // 1) Categorias
            $cats = [];
            foreach ($mybb->cursor("SELECT id, name, `order` FROM {$p}community_reviews_categories") as $r) {
                $cats[(int) $r['id']] = true;
                $this->db->table('tfb_review_categories')->insertOrIgnore([
                    'id' => (int) $r['id'],
                    'name' => (string) $r['name'],
                    'position' => (int) $r['order'],
                ]);
            }
            $this->info('Categories: ' . count($cats));

            // 2) Campos
            $fields = [];
            $batch = [];
            foreach ($mybb->cursor("SELECT id, category_id, name, `order` FROM {$p}community_reviews_fields") as $r) {
                if (! isset($cats[(int) $r['category_id']])) {
                    continue;
                }
                $fields[(int) $r['id']] = true;
                $batch[] = [
                    'id' => (int) $r['id'],
                    'category_id' => (int) $r['category_id'],
                    'name' => (string) $r['name'],
                    'position' => (int) $r['order'],
                ];
            }
            $this->flush('tfb_review_fields', $batch);
            $this->info('Fields: ' . count($fields));

            // 3) Produtos
            $products = [];
            $batch = [];
            foreach ($mybb->cursor("SELECT id, category_id, name, date, user_id, views, cached_rating FROM {$p}community_reviews_products") as $r) {
                if (! isset($cats[(int) $r['category_id']])) {
                    continue;
                }
                $products[(int) $r['id']] = true;
                $date = $this->ts((int) $r['date']);
                $batch[] = [
                    'id' => (int) $r['id'],
                    'category_id' => (int) $r['category_id'],
                    'name' => (string) $r['name'],
                    'user_id' => $this->userOrNull((int) $r['user_id'], $userIds),
                    'views' => (int) $r['views'],
                    'cached_rating' => (float) $r['cached_rating'],
                    'review_count' => 0,
                    'created_at' => $date,
                    'updated_at' => $date,
                ];
                if (count($batch) >= self::BATCH) {
                    $this->flush('tfb_products', $batch);
                    $batch = [];
                }
            }
            $this->flush('tfb_products', $batch);
            $this->info('Products: ' . count($products));

            // merchants map: review_id => user_id
            $merchants = [];
            foreach ($mybb->cursor("SELECT review_id, user_id FROM {$p}community_reviews_merchants") as $r) {
                $merchants[(int) $r['review_id']] = (int) $r['user_id'];
            }

            // 4) Reviews
            $reviews = [];
            $batch = [];
            foreach ($mybb->cursor("SELECT id, product_id, user_id, date, price, url, comment FROM {$p}community_reviews") as $r) {
                if (! isset($products[(int) $r['product_id']])) {
                    continue;
                }
                $reviews[(int) $r['id']] = true;
                $date = $this->ts((int) $r['date']);
                $merchantUid = $merchants[(int) $r['id']] ?? 0;
                $batch[] = [
                    'id' => (int) $r['id'],
                    'product_id' => (int) $r['product_id'],
                    'user_id' => $this->userOrNull((int) $r['user_id'], $userIds),
                    'merchant_user_id' => $this->userOrNull($merchantUid, $userIds),
                    'price' => $this->clip((string) $r['price'], 30),
                    'url' => $this->clip((string) $r['url'], 255),
                    'comment' => (string) $r['comment'],
                    'rating' => 0,
                    'created_at' => $date,
                    'updated_at' => $date,
                ];
                if (count($batch) >= self::BATCH) {
                    $this->flush('tfb_product_reviews', $batch);
                    $batch = [];
                }
            }
            $this->flush('tfb_product_reviews', $batch);
            $this->info('Reviews: ' . count($reviews));

            // 5) Notas por campo
            $batch = [];
            $rfCount = 0;
            foreach ($mybb->cursor("SELECT id, review_id, field_id, comment, rating FROM {$p}community_reviews_review_fields") as $r) {
                if (! isset($reviews[(int) $r['review_id']]) || ! isset($fields[(int) $r['field_id']])) {
                    continue;
                }
                $rfCount++;
                $batch[] = [
                    'id' => (int) $r['id'],
                    'review_id' => (int) $r['review_id'],
                    'field_id' => (int) $r['field_id'],
                    'rating' => max(0, min(255, (int) $r['rating'])),
                    'comment' => ($r['comment'] !== null && $r['comment'] !== '') ? (string) $r['comment'] : null,
                ];
                if (count($batch) >= self::BATCH) {
                    $this->flush('tfb_review_field_ratings', $batch);
                    $batch = [];
                }
            }
            $this->flush('tfb_review_field_ratings', $batch);
            $this->info('Field ratings: ' . $rfCount);

            // 6) Fotos
            $batch = [];
            $photoCount = 0;
            foreach ($mybb->cursor("SELECT id, review_id, url, thumbnail_url, `order` FROM {$p}community_reviews_photos") as $r) {
                if (! isset($reviews[(int) $r['review_id']])) {
                    continue;
                }
                $photoCount++;
                $batch[] = [
                    'id' => (int) $r['id'],
                    'review_id' => (int) $r['review_id'],
                    'url' => (string) $r['url'],
                    'thumbnail_url' => ($r['thumbnail_url'] !== null && $r['thumbnail_url'] !== '') ? (string) $r['thumbnail_url'] : null,
                    'position' => (int) ($r['order'] ?? 0),
                ];
                if (count($batch) >= self::BATCH) {
                    $this->flush('tfb_review_photos', $batch);
                    $batch = [];
                }
            }
            $this->flush('tfb_review_photos', $batch);
            $this->info('Photos: ' . $photoCount);

            // 7) Comentários
            $batch = [];
            $commentCount = 0;
            foreach ($mybb->cursor("SELECT id, product_id, review_id, user_id, date, comment FROM {$p}community_reviews_comments") as $r) {
                if (! isset($products[(int) $r['product_id']])) {
                    continue;
                }
                $reviewId = (int) ($r['review_id'] ?? 0);
                $commentCount++;
                $date = $this->ts((int) $r['date']);
                $batch[] = [
                    'id' => (int) $r['id'],
                    'product_id' => (int) $r['product_id'],
                    'review_id' => isset($reviews[$reviewId]) ? $reviewId : null,
                    'user_id' => $this->userOrNull((int) $r['user_id'], $userIds),
                    'comment' => (string) $r['comment'],
                    'created_at' => $date,
                    'updated_at' => $date,
                ];
                if (count($batch) >= self::BATCH) {
                    $this->flush('tfb_review_comments', $batch);
                    $batch = [];
                }
            }
            $this->flush('tfb_review_comments', $batch);
            $this->info('Comments: ' . $commentCount);
        } finally {
            $this->db->getSchemaBuilder()->enableForeignKeyConstraints();
        }

        $this->recomputeRatings();
        $this->info('Done.');

        return 0;
    }

    /**
     * Recalcula rating por review (média das notas de campo) e, por produto,
     * review_count + cached_rating (média dos ratings de review).
     */
    private function recomputeRatings(): void
    {
        $this->db->statement('
            UPDATE tfb_product_reviews r
            LEFT JOIN (
                SELECT review_id, AVG(rating) AS avg_rating
                FROM tfb_review_field_ratings GROUP BY review_id
            ) f ON f.review_id = r.id
            SET r.rating = COALESCE(f.avg_rating, 0)
        ');

        $this->db->statement('
            UPDATE tfb_products p
            LEFT JOIN (
                SELECT product_id, COUNT(*) AS c, AVG(rating) AS avg_rating
                FROM tfb_product_reviews GROUP BY product_id
            ) r ON r.product_id = p.id
            SET p.review_count = COALESCE(r.c, 0),
                p.cached_rating = COALESCE(r.avg_rating, p.cached_rating)
        ');

        $this->info('Ratings recomputed (reviews + products).');
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     */
    private function flush(string $table, array $batch): void
    {
        if ($batch !== []) {
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
