<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\Support\ImageFetcher;
use Symfony\Component\Console\Input\InputOption;

/**
 * Traz para o disco do fórum as fotos de reviews que ainda são links (imgur):
 * as que `mybb:reviews --photos=link` (o padrão) migrou e as que membros
 * mandaram pelo imgur, se o admin usava esse modo.
 *
 * Cada foto é baixada com o ImageFetcher do migrador e passada pelo
 * `Photo\Uploader` DA EXTENSÃO — o mesmo caminho de um upload pelo fórum:
 * checagem de tipo, reencode em WebP (2048 px), miniatura (480 px), disco
 * privado `storage/community-reviews`, servida por `/reviews/photo/{id}`.
 *
 * A linha original continua sendo a foto (mesmo id, review, posição, dono e
 * data — nada que aponta para ela muda, nem a capa do item): ela só recebe o
 * `path`/`thumb_path`/tamanho do arquivo novo e perde o `url`/`thumb_url`,
 * exatamente como fica uma foto enviada direto para o disco. A linha
 * temporária que o Uploader cria é apagada (sem apagar os arquivos).
 *
 * Rodar de novo é seguro: só entram fotos sem `path`. Uma foto que falha
 * (link morto, imgur fora, arquivo recusado pela extensão) continua como link,
 * é contada e entra na próxima rodada.
 */
class LocalizeReviewPhotosCommand extends AbstractCommand
{
    private const TABLE = 'community_review_photos';

    public function __construct(
        protected ConnectionInterface $db,
        protected Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:reviews-localize-photos')
            ->setDescription('Download the community-reviews photos that are still links (imgur) and store them on the extension\'s own disk, like a local upload.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm the execution (downloads and rewrites the photo rows).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the photos that would be downloaded.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Process at most N photos (try a sample first).')
            ->addOption('host-delay', null, InputOption::VALUE_REQUIRED, 'Milliseconds between requests to the same host.', '250')
            ->addOption('insecure', null, InputOption::VALUE_NONE, 'Skip TLS certificate verification.');
    }

    protected function fire(): int
    {
        if (! class_exists(\ErnestDefoe\CommunityReviews\Photo\Uploader::class) || ! $this->db->getSchemaBuilder()->hasTable(self::TABLE)) {
            $this->error('ernestdefoe/community-reviews is not installed/enabled (no Photo\\Uploader or community_review_photos table).');
            return 1;
        }

        $dryRun = (bool) $this->input->getOption('dry-run');
        if (! $dryRun && ! $this->input->getOption('force')) {
            $this->error('This command downloads files and rewrites photo rows. Run with --force (or --dry-run to only count).');
            return 1;
        }

        $limit = max(0, (int) ($this->input->getOption('limit') ?? 0));

        $pending = $this->db->table(self::TABLE)
            ->whereNull('path')
            ->whereNotNull('url')
            ->where('url', '!=', '')
            ->whereNotNull('review_id');

        $total = (clone $pending)->count();
        $this->info("[mybb:reviews-localize-photos] Photos still stored as links: {$total}");

        if ($dryRun) {
            $hosts = [];
            foreach ((clone $pending)->select('url')->cursor() as $row) {
                $host = strtolower((string) parse_url((string) $row->url, PHP_URL_HOST));
                $hosts[$host] = ($hosts[$host] ?? 0) + 1;
            }
            arsort($hosts);
            foreach ($hosts as $host => $count) {
                $this->info("  {$host}: {$count}");
            }
            $this->info('[mybb:reviews-localize-photos] DRY-RUN — nothing downloaded.');
            return 0;
        }

        $fetcher = new ImageFetcher(20, 10 * 1024 * 1024, 3, max(0, (int) $this->input->getOption('host-delay')), null, ! $this->input->getOption('insecure'));
        $uploader = $this->container->make(\ErnestDefoe\CommunityReviews\Photo\Uploader::class);
        $storage = $this->container->make(\ErnestDefoe\CommunityReviews\Photo\Storage::class);

        $done = $failed = $noOwner = $seen = 0;

        // Por id e em lotes: cada foto resolvida sai do filtro (ganha `path`),
        // então o cursor por id nunca repete nem pula linha.
        $lastId = 0;
        while (true) {
            $rows = (clone $pending)->where('id', '>', $lastId)->orderBy('id')->limit(100)->get();
            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                if ($limit > 0 && $seen >= $limit) {
                    break 2;
                }
                $seen++;

                $user = User::query()->find($row->user_id);
                if ($user === null) {
                    $noOwner++;
                    $this->info("  photo {$row->id}: owner {$row->user_id} not found; keeping the link.");
                    continue;
                }

                $this->localize($fetcher, $uploader, $storage, $user, $row) ? $done++ : $failed++;
            }
        }

        $this->info('[mybb:reviews-localize-photos] Done.');
        $this->info("  stored locally: {$done}");
        $this->info("  still links (download failed or file refused; re-run to retry): {$failed}");
        if ($noOwner > 0) {
            $this->info("  still links (owner not in Flarum): {$noOwner}");
        }
        $left = $this->db->table(self::TABLE)->whereNull('path')->whereNotNull('url')->where('url', '!=', '')->whereNotNull('review_id')->count();
        $this->info("  photos still stored as links: {$left}");

        return 0;
    }

    private function localize(ImageFetcher $fetcher, object $uploader, object $storage, User $user, object $row): bool
    {
        // A foto inteira, não a miniatura: o Uploader refaz a miniatura dela.
        $res = $fetcher->fetchImage((string) $row->url);
        if (! $res['ok'] || $res['bytes'] === null) {
            $this->info("  photo {$row->id}: download failed ({$res['error']}); keeping the link.");
            return false;
        }

        $bytes = (string) $res['bytes'];
        $stream = new \Laminas\Diactoros\Stream('php://temp', 'w+b');
        $stream->write($bytes);
        $stream->rewind();
        $file = new \Laminas\Diactoros\UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, 'review-photo-' . $row->id);

        try {
            $temp = $uploader->upload($user, $file);
        } catch (\Throwable $e) {
            $this->info("  photo {$row->id}: rejected by the extension ({$e->getMessage()}); keeping the link.");
            return false;
        }

        try {
            $this->db->transaction(function () use ($row, $temp): void {
                $this->db->table(self::TABLE)->where('id', $row->id)->update([
                    'path' => $temp->path,
                    'thumb_path' => $temp->thumb_path,
                    'width' => $temp->width,
                    'height' => $temp->height,
                    'url' => null,
                    'thumb_url' => null,
                ]);
                // A linha temporária do Uploader: os arquivos agora são da original.
                $this->db->table(self::TABLE)->where('id', $temp->id)->delete();
            });
        } catch (\Throwable $e) {
            // Nada mudou na original: apaga a temporária e os arquivos dela.
            $this->db->table(self::TABLE)->where('id', $temp->id)->delete();
            $storage->delete([$temp->path, $temp->thumb_path]);
            $this->error("  photo {$row->id}: could not update the row ({$e->getMessage()}); keeping the link.");
            return false;
        }

        return true;
    }
}
