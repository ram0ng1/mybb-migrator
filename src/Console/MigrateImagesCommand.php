<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Foundation\Paths;
use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\Gui\MediaDetector;
use Ramon\MybbMigrator\Gui\StepStore;
use Ramon\MybbMigrator\Support\ImageFetcher;
use Ramon\MybbMigrator\Support\ImageOptimizer;
use Ramon\MybbMigrator\Support\ImageStore;
use Ramon\MybbMigrator\Support\PrivateUploadBridge;
use Ramon\MybbMigrator\Support\UploadVisibilityBridge;
use Symfony\Component\Console\Input\InputOption;

/**
 * Traz para dentro do Flarum as imagens que os posts migrados ainda buscam em
 * servidores de terceiros (imgur, flickr, o próprio domínio antigo...).
 *
 * Para cada `<IMG src="...">` do `posts.content` cujo host case com o filtro
 * configurado: baixa o arquivo, grava em `public/assets/files` (a mesma pasta do
 * fof/upload), registra em `fof_upload_files` quando a extensão existe, e troca
 * a URL remota pela local dentro do XML do post.
 *
 * Salvaguardas pensadas para TESTAR ANTES de migrar tudo:
 *  - `--limit` (nº de downloads novos) e `--max-mb` (orçamento total do run):
 *    dá para localizar 20 imagens, olhar o fórum, e só então soltar o resto.
 *  - `--dry-run`: relatório completo sem tocar em disco nem no banco.
 *  - URLs já baixadas ficam em `mybb_migrated_images` e são REAPONTADAS sem
 *    rede; URLs mortas ficam como `failed` e não são retentadas (a menos de
 *    `--retry-failed`). É o "pular links já populados". Falha TRANSITÓRIA
 *    (HTTP 429, timeout) fica como `deferred` e volta sozinha no próximo run.
 *  - o que é baixado passa pelo ImageOptimizer antes de virar arquivo: WebP,
 *    limite de dimensão, e recusa de otimizar o que pioraria (`--no-optimize`
 *    desliga tudo).
 *  - `--relink-only`: nenhum acesso à rede; só reaplica o mapa já existente —
 *    use depois de `mybb:rebuild-formatting`, que regenera os posts a partir do
 *    MyBB e devolveria as URLs remotas.
 *
 * O conteúdo do post é XML do s9e/TextFormatter, então a troca é textual sobre a
 * forma JÁ ESCAPADA da URL: isso cobre de uma vez o atributo `src` e o token
 * `[img]...[/img]` que o formatter guarda ao lado dele.
 */
class MigrateImagesCommand extends AbstractCommand
{
    use MediaFetchOptions;
    use TranslatesOutput;

    /** Contadores exibidos no resumo (o painel lê "rótulo: número"). */
    private int $scanned = 0;
    private int $found = 0;
    private int $downloaded = 0;
    /** URLs novas processadas neste run (sucesso ou falha) — é o que --limit conta. */
    private int $attempted = 0;
    private int $relinked = 0;
    private int $postsUpdated = 0;
    private int $skippedHost = 0;
    private int $skippedLocal = 0;
    private int $skippedFailed = 0;
    private int $failed = 0;
    /** Falhas TRANSITÓRIAS (429/timeout/5xx): voltam sozinhas na próxima execução. */
    private int $deferred = 0;
    private int $bytes = 0;
    /** Imagens re-encodadas (webp/redimensionadas) e bytes poupados por isso. */
    private int $optimized = 0;
    private int $savedBytes = 0;
    /** Arquivos gravados direto fora do document root (discussão restrita). */
    private int $privateWrites = 0;
    private bool $budgetHit = false;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected ImageStore $store,
        protected Paths $paths,
        protected StepStore $steps,
        protected MediaDetector $detector,
        protected UploadVisibilityBridge $visibility,
        protected PrivateUploadBridge $private,
        protected LocaleManager $locales,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:images')
            ->setDescription('Downloads remote images referenced by migrated posts into fof/upload\'s local folder and rewrites the posts to point at them.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report only: no downloads, no writes.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum NEW images to attempt in this run, downloaded or failed (0 = no cap).')
            ->addOption('max-mb', null, InputOption::VALUE_REQUIRED, 'Total download budget for this run, in MB (0 = no cap).')
            ->addOption('max-file-mb', null, InputOption::VALUE_REQUIRED, 'Per-file size cap, in MB.')
            ->addOption('hosts', null, InputOption::VALUE_REQUIRED, 'Comma-separated hosts/URL prefixes to localize (overrides the setting).')
            ->addOption('all-hosts', null, InputOption::VALUE_NONE, 'Localize every external image, ignoring the host filter.')
            ->addOption('posts', null, InputOption::VALUE_REQUIRED, 'Maximum posts to scan.')
            ->addOption('from-id', null, InputOption::VALUE_REQUIRED, 'Start scanning at this post id.')
            ->addOption('discussion', null, InputOption::VALUE_REQUIRED, 'Only this discussion: accepts an id, a slug or a full Flarum discussion URL.')
            ->addOption('retry-failed', null, InputOption::VALUE_NONE, 'Try URLs previously recorded as failed again.')
            ->addOption('relink-only', null, InputOption::VALUE_NONE, 'No network: only re-apply URLs already downloaded.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Idle timeout per request, in seconds: a download that keeps progressing is no longer killed.');
        $this->addMediaFetchOptions();
        $this->addLocaleOption();
    }

    protected function fire(): int
    {
        $this->applyLocale();

        if (! $this->input->getOption('force')) {
            $this->error($this->trans('common.force_required'));

            return 1;
        }

        $dryRun = (bool) $this->input->getOption('dry-run');
        $relinkOnly = (bool) $this->input->getOption('relink-only');
        $retryFailed = (bool) $this->input->getOption('retry-failed');

        $rawDiscussion = (string) ($this->input->getOption('discussion') ?? '');
        $discussionId = $this->resolveDiscussion($rawDiscussion);

        if ($rawDiscussion !== '' && $discussionId === null) {
            $this->error($this->trans('images.discussion_unresolved', ['value' => $rawDiscussion]));

            return 1;
        }

        // Uma discussão JÁ É o limite: aplicar o teto padrão de 50 downloads aqui
        // truncaria o pedido explícito do admin ("trate esta discussão"). Só o
        // --limit passado na mão continua valendo.
        $defaultLimit = $discussionId === null
            ? (int) ($this->settings->get('mybb-migrator.image_limit') ?: 50)
            : 0;

        $limit = $this->intOpt('limit', $defaultLimit);
        $maxMb = $this->intOpt('max-mb', (int) ($this->settings->get('mybb-migrator.image_max_mb') ?: 200));
        $maxFileMb = $this->intOpt('max-file-mb', (int) ($this->settings->get('mybb-migrator.image_max_file_mb') ?: 10));
        $maxPosts = $this->intOpt('posts', 0);
        $fromId = $this->intOpt('from-id', 0);
        $timeout = $this->intOpt('timeout', 20) ?: 20;

        $hosts = $this->resolveHosts();
        $allHosts = (bool) $this->input->getOption('all-hosts');

        // Nada configurado? Em vez de exigir que o admin adivinhe, descobrimos:
        // os hosts saem dos próprios posts migrados, rankeados por uso.
        if ($hosts === [] && ! $allHosts && ! $relinkOnly) {
            $this->info($this->trans('images.detecting_hosts'));
            $detected = $this->detector->detectHosts();

            $hosts = $detected['applied'];
            if ($hosts === []) {
                $this->error($this->trans('images.no_external_images'));

                return 1;
            }

            $this->settings->set('mybb-migrator.image_hosts', implode(',', $hosts));
            $this->info($this->trans('images.hosts_applied', [
                'hosts'   => count($hosts),
                'images'  => number_format((int) ($detected['total_images'] ?? 0), 0, ',', '.'),
                'scanned' => $detected['scanned'],
                'sample'  => $detected['truncated'] ? ' ' . $this->trans('images.sample_suffix') : '',
            ]));

            foreach (array_slice($detected['ranking'], 0, 10) as $entry) {
                $this->info("    {$entry['host']}: {$entry['count']}");
            }

            if ($detected['total_hosts'] > 10) {
                $this->info($this->trans('images.hosts_more', ['count' => $detected['total_hosts'] - 10]));
            }
        }

        $fetcher = $this->buildFetcher($this->settings, $timeout, max(1, $maxFileMb) * 1048576);
        $optimizer = $this->buildOptimizer($this->settings);
        $budgetBytes = $maxMb > 0 ? $maxMb * 1048576 : 0;

        $this->info($this->trans('common.line_destination', [
            'path' => $this->store->directoryHint($this->paths->public),
        ]));
        if ($this->private->available()) {
            $this->info($this->trans('images.restricted', ['path' => $this->private->directoryHint()]));
        }
        $this->info($this->trans('images.line_hosts', [
            'hosts' => $allHosts ? $this->trans('images.hosts_all') : implode(', ', $hosts),
        ]));
        $this->info($this->trans('common.line_limits', [
            'limits' => $this->trans($limit > 0 ? 'images.limit_downloads' : 'images.limit_downloads_none', ['count' => $limit])
                . ' / ' . $this->trans($maxMb > 0 ? 'common.limit_volume' : 'common.limit_volume_none', ['count' => $maxMb])
                . ' / ' . $this->trans('common.limit_per_file', ['count' => $maxFileMb]),
        ]));
        $this->info($this->trans('common.line_network', ['summary' => $this->describeFetch($this->settings, $timeout)]));
        $this->info($this->trans('common.line_optimize', ['summary' => $this->describeOptimizer($optimizer)]));

        if (! $this->store->uploadTableAvailable()) {
            $this->info($this->trans('images.no_upload_table'));
        }
        if ($dryRun) {
            $this->info($this->trans('common.dry_run'));
        }
        if ($relinkOnly) {
            $this->info($this->trans('images.relink_only'));
        }

        $map = $this->loadMap();
        $this->info($this->trans('images.map_known', ['count' => count($map)]));

        $query = $this->db->table('posts')
            ->select('id', 'user_id', 'discussion_id', 'content')
            ->where('type', 'comment')
            ->where('content', 'like', '%<IMG %');

        if ($fromId > 0) {
            $query->where('id', '>=', $fromId);
        }

        if ($discussionId !== null) {
            $query->where('discussion_id', $discussionId);
        }

        // Total exato só quando é barato: numa discussão o COUNT é instantâneo e
        // a barra do painel vira percentual de verdade. Na varredura completa
        // (centenas de milhares de posts com LIKE) contar antes custaria mais que
        // o próprio trabalho, então o progresso fica sem total — barra
        // indeterminada em vez de porcentagem inventada.
        $total = $discussionId !== null ? (clone $query)->count() : null;
        if ($discussionId !== null) {
            $this->info($this->trans('common.line_scope', [
                'scope' => $this->trans('images.scope_discussion', ['id' => $discussionId, 'count' => $total]),
            ]));
        }
        $this->publishProgress(0, $total);

        $stop = false;

        $query->orderBy('id')->chunkById(200, function ($rows) use (
            &$map, &$stop, $fetcher, $optimizer, $dryRun, $relinkOnly, $retryFailed,
            $limit, $budgetBytes, $maxPosts, $allHosts, $hosts, $total
        ) {
            foreach ($rows as $row) {
                if ($stop) {
                    return false;
                }

                $this->scanned++;

                $content = (string) $row->content;
                $replacements = [];
                // Num dry-run nada é reescrito, mas ainda queremos dizer QUANTOS
                // posts seriam tocados — senão o relatório de teste mostra zero.
                $wouldTouch = false;

                foreach ($this->extractSources($content) as $escaped => $rawUrl) {
                    if ($this->isLocal($rawUrl)) {
                        $this->skippedLocal++;
                        continue;
                    }
                    if (! $allHosts && ! $this->hostMatches($rawUrl, $hosts)) {
                        $this->skippedHost++;
                        continue;
                    }

                    $this->found++;
                    $hash = sha1($rawUrl);
                    $known = $map[$hash] ?? null;

                    if ($known !== null && $known['status'] === 'ok') {
                        $replacements[(string) $escaped] = (string) $known['local_url'];
                        $this->relinked++;
                        $wouldTouch = true;

                        // A MESMA imagem remota aparece em vários posts, e o mapa
                        // faz este ramo atender todos menos o primeiro. Sem criar
                        // o vínculo aqui, `fof_upload_file_posts` só conhece o
                        // post original — e o escopo por tag, que descobre as
                        // discussões de um arquivo justamente por essa pivô,
                        // classificaria a imagem pelas tags do post errado: uma
                        // imagem vista primeiro num tópico aberto continuaria
                        // pública depois de ser postada dentro de uma tag
                        // restrita.
                        if (! $dryRun) {
                            $this->linkKnown($known, (int) $row->id);
                        }

                        continue;
                    }

                    // Falha TRANSITÓRIA (429 do imgur, lentidão do postimg) volta
                    // sozinha na execução seguinte: exigir --retry-failed nesse
                    // caso transformaria um soluço de rede em imagem perdida para
                    // sempre. Só o que morreu de verdade (404, removida) fica
                    // congelado até alguém pedir a retentativa.
                    if ($known !== null && $known['status'] !== 'deferred' && ! $retryFailed) {
                        $this->skippedFailed++;
                        continue;
                    }

                    if ($relinkOnly) {
                        continue;
                    }

                    // Orçamento do run: para de baixar (e de varrer) ao estourar.
                    // O limite conta TENTATIVAS, não só sucessos: uma sequência de
                    // URLs mortas custa tempo de rede igual, e um teste com
                    // --limit=5 deve terminar rápido de qualquer jeito.
                    if (($limit > 0 && $this->attempted >= $limit)
                        || ($budgetBytes > 0 && $this->bytes >= $budgetBytes)) {
                        $this->budgetHit = true;
                        $stop = true;
                        break;
                    }

                    $this->attempted++;
                    $local = $this->download($fetcher, $optimizer, $rawUrl, $dryRun, (int) $row->id, $row->user_id === null ? null : (int) $row->user_id, (int) $row->discussion_id, $map);

                    if ($local !== null) {
                        $replacements[(string) $escaped] = $local;
                        $wouldTouch = true;
                    } elseif ($dryRun) {
                        $wouldTouch = true;
                    }
                }

                if ($dryRun) {
                    if ($wouldTouch) {
                        $this->postsUpdated++;
                    }
                } elseif ($replacements !== []) {
                    $new = strtr($content, $this->escapeTargets($replacements));
                    if ($new !== $content) {
                        $this->db->table('posts')->where('id', $row->id)->update(['content' => $new]);
                        $this->postsUpdated++;
                    }
                }

                // Heartbeat + barra: mais frequente quando há total (escopo de
                // discussão, onde cada post pesa muito na porcentagem).
                $every = $total === null ? 25 : 1;
                if ($this->scanned % $every === 0) {
                    $this->publishProgress($this->scanned, $total);
                }

                if ($this->scanned % 500 === 0) {
                    $this->info($this->trans('images.progress', [
                        'scanned'    => $this->scanned,
                        'downloaded' => $this->downloaded,
                    ]));
                }

                if ($maxPosts > 0 && $this->scanned >= $maxPosts) {
                    $stop = true;

                    return false;
                }
            }

            return ! $stop;
        }, 'id');

        $this->publishProgress($this->scanned, $total);

        // Fecha o passo já com a proteção aplicada. Deixar isso para um comando
        // avulso significa que, entre o fim da importação e alguém lembrar de
        // rodá-lo, toda imagem de tag restrita fica com uma URL pública válida.
        if (! $dryRun && ($moved = $this->visibility->flush()) > 0) {
            $this->info($this->trans('images.moved_private', ['count' => $moved]));
        }

        $this->report($dryRun);

        return 0;
    }

    /**
     * Vincula ao post uma imagem que já havia sido baixada num run anterior,
     * resolvendo o id pela linha do mapa ou, quando ela não o guardou (execução
     * antiga, ou registro que falhou na época), pelo nome do arquivo.
     *
     * @param array<string, mixed> $known
     */
    private function linkKnown(array $known, int $postId): void
    {
        $fileId = isset($known['file_id']) ? (int) $known['file_id'] : 0;

        if ($fileId <= 0) {
            $name = (string) ($known['local_name'] ?? '');
            $fileId = $name === '' ? 0 : (int) ($this->store->fileIdForPath($name) ?? 0);
        }

        if ($fileId <= 0) {
            return;
        }

        $this->store->linkToPost($fileId, $postId);
        $this->visibility->touch($fileId);
    }

    /**
     * Publica o progresso na linha do passo (só tem efeito quando o painel o
     * lançou; rodando direto pelo CLI é um no-op).
     */
    private function publishProgress(int $done, ?int $total): void
    {
        $this->steps->progress(
            'images',
            $done,
            $total,
            $this->downloaded > 0 ? "{$this->downloaded} baixadas" : null
        );
    }

    /**
     * Aceita id puro, slug (`1661-done-and-dusted`) ou a URL inteira da discussão
     * no Flarum — colar o endereço da barra do navegador é o caminho natural.
     */
    private function resolveDiscussion(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (ctype_digit($raw)) {
            return (int) $raw;
        }

        // .../d/1661-done-and-dusted-... ou só o slug "1661-done-and-dusted"
        if (preg_match('#(?:^|/d/)(\d+)(?:-|/|$)#', $raw, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Baixa uma URL, grava o arquivo e registra o mapa. Devolve a URL local, ou
     * null em caso de falha (registrada para não ser retentada).
     *
     * @param array<string, array<string, mixed>> $map
     */
    private function download(
        ImageFetcher $fetcher,
        ImageOptimizer $optimizer,
        string $url,
        bool $dryRun,
        int $postId,
        ?int $actorId,
        int $discussionId,
        array &$map,
    ): ?string {
        if ($dryRun) {
            $this->downloaded++;
            $this->info($this->trans('images.dry_download', ['url' => $url]));

            return null;
        }

        $res = $fetcher->fetchImage($url);

        if (! $res['ok']) {
            // `transient` = o host recusou (429) ou demorou, NÃO que a imagem
            // morreu. Gravar isso como 'failed' aposentaria a URL para sempre.
            $transient = (bool) ($res['transient'] ?? false);
            $transient ? $this->deferred++ : $this->failed++;

            $this->info($this->trans($transient ? 'images.deferred' : 'images.failed', [
                'url'   => $url,
                'error' => $res['error'] ?? $this->trans('common.unknown_error'),
            ]));
            $this->remember($url, [
                'status'    => $transient ? 'deferred' : 'failed',
                'error'     => $res['error'],
                'local_url' => null,
            ], $map);

            return null;
        }

        $downloaded = strlen((string) $res['bytes']);

        // Re-encode ANTES de escolher o nome: a extensão faz parte do nome do
        // arquivo, então um jpg que vira webp precisa nascer já como .webp.
        $opt = $optimizer->optimize((string) $res['bytes'], $res['mime'], $res['ext']);
        if ($opt['changed']) {
            $this->optimized++;
            $this->savedBytes += $opt['saved'];
            $this->info($this->trans('common.optimized_url', ['note' => $opt['note']]));
        }

        $bytes = $opt['bytes'];
        $name = $this->store->nameFor($url, $opt['ext']);

        // O LADO é decidido antes de gravar. Um arquivo de discussão restrita
        // nunca chega a existir sob o document root, nem por um instante — a
        // reconciliação do fim do passo corrige classificações, não exposições
        // que já aconteceram.
        $private = $this->private->shouldBePrivate($discussionId);

        if (! $this->store->exists($name)) {
            $localUrl = $this->store->put($name, $bytes, $private);
            if ($private) {
                $this->privateWrites++;
            }
        } else {
            // Já existia: quem decide o lado é o flush do fim, que enxerga TODAS
            // as discussões do arquivo (a regra do dfs é a menos restritiva).
            $private = null;
            $localUrl = $this->store->urlFor($name);
        }

        $fileId = $this->store->registerUploadFile(
            $name,
            $localUrl,
            $opt['mime'],
            strlen($bytes),
            $actorId,
            $postId,
            $discussionId,
            basename((string) parse_url($url, PHP_URL_PATH)) ?: $name,
            $bytes,
        );

        if ($private === true) {
            $this->private->markPrivate((int) $fileId);
        }

        $this->downloaded++;
        // O orçamento do run (--max-mb) mede TRÁFEGO, então conta o que veio da
        // rede — não o que sobrou depois de otimizar.
        $this->bytes += $downloaded;
        $this->visibility->touch($fileId);

        $this->remember($url, [
            'status'     => 'ok',
            'local_name' => $name,
            'local_url'  => $localUrl,
            'size'       => strlen($bytes),
            'mime'       => $opt['mime'],
            'file_id'    => $fileId,
            'error'      => null,
        ], $map);

        return $localUrl;
    }

    /**
     * Grava (ou atualiza) a linha do mapa e mantém o cache em memória coerente.
     *
     * @param array<string, mixed> $data
     * @param array<string, array<string, mixed>> $map
     */
    private function remember(string $url, array $data, array &$map): void
    {
        $hash = sha1($url);

        $row = array_merge([
            'url_hash'   => $hash,
            'source_url' => $url,
            'kind'       => 'image',
            'created_at' => date('Y-m-d H:i:s'),
        ], $data);

        try {
            $this->db->table('mybb_migrated_images')->updateOrInsert(['url_hash' => $hash], $row);
        } catch (\Throwable $e) {
            $this->error($this->trans('common.map_write_failed', ['key' => $url, 'error' => $e->getMessage()]));
        }

        $map[$hash] = [
            'status'    => (string) ($data['status'] ?? 'ok'),
            'local_url' => $data['local_url'] ?? null,
        ];
    }

    /**
     * Mapa url_hash => {status, local_url} das URLs já processadas.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadMap(): array
    {
        $map = [];

        try {
            foreach ($this->db->table('mybb_migrated_images')->select('url_hash', 'status', 'local_url')->cursor() as $row) {
                $map[(string) $row->url_hash] = [
                    'status'    => (string) $row->status,
                    'local_url' => $row->local_url,
                ];
            }
        } catch (\Throwable $e) {
            $this->error($this->trans('common.map_missing', ['error' => $e->getMessage()]));
        }

        return $map;
    }

    /**
     * URLs de imagem do XML do post: chave = forma ESCAPADA (como aparece no
     * conteúdo, pronta para str_replace), valor = URL real para baixar.
     *
     * @return array<string, string>
     */
    private function extractSources(string $content): array
    {
        if (! preg_match_all('/<IMG\b[^>]*\ssrc="([^"]*)"/i', $content, $matches)) {
            return [];
        }

        $out = [];
        foreach ($matches[1] as $escaped) {
            if ($escaped === '') {
                continue;
            }
            $out[$escaped] = html_entity_decode($escaped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $out;
    }

    /**
     * Converte o par (origem escapada => URL local crua) no par pronto para
     * strtr sobre o XML — a URL nova também precisa ir escapada.
     *
     * @param array<string, string> $replacements
     * @return array<string, string>
     */
    private function escapeTargets(array $replacements): array
    {
        $out = [];
        foreach ($replacements as $escapedOld => $newUrl) {
            $out[$escapedOld] = htmlspecialchars($newUrl, ENT_COMPAT | ENT_XML1, 'UTF-8');
        }

        return $out;
    }

    /** A URL já aponta para este Flarum (relativa ou sob a pasta de assets)? */
    private function isLocal(string $url): bool
    {
        if (! preg_match('#^https?://#i', $url)) {
            return true; // relativa: já é interna
        }

        static $base = null;
        if ($base === null) {
            $sample = $this->store->urlFor('x');
            $base = substr($sample, 0, strrpos($sample, '/') + 1);
        }

        return str_starts_with($url, $base);
    }

    /**
     * Entradas com `://` casam por prefixo de URL; as demais, por sufixo de host
     * (então `imgur.com` pega também `i.imgur.com`).
     *
     * @param array<int, string> $hosts
     */
    private function hostMatches(string $url, array $hosts): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach ($hosts as $entry) {
            if (str_contains($entry, '://')) {
                if (stripos($url, $entry) === 0) {
                    return true;
                }
                continue;
            }

            if ($host === $entry || str_ends_with($host, '.' . $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function resolveHosts(): array
    {
        $raw = (string) ($this->input->getOption('hosts') ?? '');
        if (trim($raw) === '') {
            $raw = (string) ($this->settings->get('mybb-migrator.image_hosts') ?? '');
        }

        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $entry) {
            $entry = strtolower(trim((string) $entry));
            if ($entry === '') {
                continue;
            }
            // Entrada com esquema mas sem caminho vira só o host (mais tolerante).
            if (str_contains($entry, '://')) {
                $path = (string) parse_url($entry, PHP_URL_PATH);
                if ($path === '' || $path === '/') {
                    $entry = (string) parse_url($entry, PHP_URL_HOST);
                } else {
                    $entry = rtrim($entry, '/');
                }
            }
            if ($entry !== '' && ! in_array($entry, $out, true)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    private function intOpt(string $name, int $default): int
    {
        $value = $this->input->getOption($name);

        return $value === null || $value === '' ? $default : max(0, (int) $value);
    }

    private function report(bool $dryRun): void
    {
        $this->info($this->trans($dryRun ? 'common.dry_run_done' : 'common.done'));
        $this->stat('images.stats.scanned', $this->scanned);
        $this->stat('images.stats.candidates', $this->found);
        $this->stat('images.stats.downloaded', $this->downloaded);
        $this->stat('images.stats.private', $this->privateWrites);
        $this->stat('images.stats.relinked', $this->relinked);
        $this->stat('images.stats.posts', $this->postsUpdated);
        $this->stat('images.stats.mb', round($this->bytes / 1048576, 2));
        $this->stat('common.stats.optimized', $this->optimized);
        $this->stat('common.stats.saved', round($this->savedBytes / 1048576, 2));
        $this->stat('images.stats.skipped_host', $this->skippedHost);
        $this->stat('images.stats.skipped_local', $this->skippedLocal);
        $this->stat('images.stats.skipped_failed', $this->skippedFailed);
        $this->stat('common.stats.failed', $this->failed);
        $this->stat('common.stats.deferred', $this->deferred);

        if ($this->budgetHit) {
            $this->info($this->trans('images.budget_hit'));
        }

        if ($this->failed > 0) {
            $this->info($this->trans('images.failed_hint'));
        }

        if ($this->deferred > 0) {
            $this->info($this->trans('images.deferred_hint', ['count' => $this->deferred]));
        }
    }
}
