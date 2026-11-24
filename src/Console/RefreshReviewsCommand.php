<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Recalcula os campos derivados da community-reviews para TODOS os itens e
 * categorias, com o Aggregates da própria extensão (o mesmo que o mybb:reviews
 * roda no fim): nota, rating_rank (média bayesiana do "Top rated"), contagens,
 * distribuição, capa e última atividade. Não lê o MyBB nem mexe em review.
 *
 * Para que serve: a extensão só recalcula um item quando ele recebe review ou
 * comentário. Mudar no admin "Reviews before a product is ranked"
 * (rank_min_reviews) não atualiza os itens que já existem — os migrados
 * continuam fora do "Top rated" (e do destaque da página) até alguém escrever
 * uma review neles. Rodar este comando depois de mudar o valor aplica a regra
 * nova a tudo de uma vez.
 */
class RefreshReviewsCommand extends AbstractCommand
{
    public function __construct(
        protected ConnectionInterface $db,
        protected Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:reviews-refresh')
            ->setDescription('Recompute ratings, ranking (Top rated), counts and covers of every community-reviews product and category, with the extension\'s own Aggregates. Run it after changing "Reviews before a product is ranked".')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm the execution (rewrites the derived columns).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show how many products would gain or lose a rank with the current setting.');
    }

    protected function fire(): int
    {
        if (! class_exists(\ErnestDefoe\CommunityReviews\Aggregates::class) || ! $this->db->getSchemaBuilder()->hasTable('community_review_items')) {
            $this->error('ernestdefoe/community-reviews is not installed/enabled.');
            return 1;
        }

        $dryRun = (bool) $this->input->getOption('dry-run');
        if (! $dryRun && ! $this->input->getOption('force')) {
            $this->error('Run with --force (or --dry-run to only count).');
            return 1;
        }

        $min = max(1, (int) ($this->db->table('settings')->where('key', 'ernestdefoe-community-reviews.rank_min_reviews')->value('value') ?? 3));
        $items = $this->db->table('community_review_items');
        $ranked = (clone $items)->whereNotNull('rating_rank')->count();
        $shouldRank = (clone $items)->where('status', 'approved')->whereNotNull('rating')->where('review_count', '>=', $min)->count();

        $this->info("[mybb:reviews-refresh] Reviews before a product is ranked: {$min}");
        $this->info("  products ranked now: {$ranked}");
        $this->info("  products that qualify with this setting: {$shouldRank}");

        if ($dryRun) {
            $this->info('[mybb:reviews-refresh] DRY-RUN — nothing written.');
            return 0;
        }

        /** @var \ErnestDefoe\CommunityReviews\Aggregates $aggregates */
        $aggregates = $this->container->make(\ErnestDefoe\CommunityReviews\Aggregates::class);

        $itemIds = (clone $items)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $categoryIds = $this->db->table('community_review_categories')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Duas passadas, como no mybb:reviews: o rating_rank puxa para a média
        // da categoria, que só fica certa depois que todos os itens dela passaram.
        foreach ([1, 2] as $_) {
            foreach ($itemIds as $id) {
                $aggregates->refreshItem($id);
            }
        }
        foreach ($categoryIds as $id) {
            $aggregates->refreshCategory($id);
        }

        $after = (clone $items)->whereNotNull('rating_rank')->count();
        $this->info('[mybb:reviews-refresh] Done: ' . count($itemIds) . ' products, ' . count($categoryIds) . ' categories.');
        $this->info("  products ranked now: {$after}");

        return 0;
    }
}
