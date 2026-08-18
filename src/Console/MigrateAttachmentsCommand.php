<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Formatter\Formatter;
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
 * Migra os ANEXOS do MyBB (`{prefix}attachments`) para o armazenamento local do
 * fof/upload e os acrescenta ao post correspondente.
 *
 * De onde vêm os bytes, em ordem de preferência:
 *  1. `--uploads-dir=/caminho/do/mybb/uploads` — cópia direta de `attachname`
 *     (é o caminho confiável: não depende de o fórum antigo estar no ar nem de
 *     permissão de download).
 *  2. `<old_site_url>/attachment.php?aid=<aid>` — funciona quando os anexos são
 *     públicos. Se o MyBB exigir login, a resposta é HTML e o item é registrado
 *     como falha, com a mensagem explicando o motivo.
 *
 * O `Converter` descarta os tokens `[attachment=N]` na migração de conteúdo (o
 * anexo ainda não existia no destino), então aqui os arquivos são ANEXADOS AO
 * FIM do post — que é, aliás, como o próprio MyBB exibe os anexos não embutidos.
 * O post é reescrito via unparse → texto → parse, o mesmo caminho que o Flarum
 * usa ao editar um post, de modo que o XML resultante é sempre válido.
 *
 * Idempotente: cada `aid` vira uma linha em `mybb_migrated_images` (kind
 * `attachment`) e o post só recebe o anexo se ainda não contiver a URL local.
 */
class MigrateAttachmentsCommand extends AbstractCommand
{
    use MediaFetchOptions;
    use MybbConnectionOptions;
    use TranslatesOutput;

    private int $seen = 0;
    private int $copied = 0;
    /** Anexos novos processados neste run (sucesso ou falha) — é o que --limit conta. */
    private int $attempted = 0;
    private int $appended = 0;
    private int $alreadyDone = 0;
    private int $missingPost = 0;
    private int $failed = 0;
    /** Falhas TRANSITÓRIAS (429/timeout/5xx): voltam sozinhas na próxima execução. */
    private int $deferred = 0;
    private int $bytes = 0;
    /** Anexos de imagem re-encodados e bytes poupados por isso. */
    private int $optimized = 0;
    private int $savedBytes = 0;
    /** Arquivos gravados direto fora do document root (discussão restrita). */
    private int $privateWrites = 0;
    private bool $budgetHit = false;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected ImageStore $store,
        protected Formatter $formatter,
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
            ->setName('mybb:attachments')
            ->setDescription('Migrates MyBB post attachments into fof/upload local storage and appends them to the migrated posts.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report only: no downloads, no writes.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum NEW attachments to attempt in this run, migrated or failed (0 = no cap).')
            ->addOption('max-mb', null, InputOption::VALUE_REQUIRED, 'Total budget for this run, in MB (0 = no cap).')
            ->addOption('max-file-mb', null, InputOption::VALUE_REQUIRED, 'Per-file size cap, in MB.')
            ->addOption('uploads-dir', null, InputOption::VALUE_REQUIRED, 'Path to the MyBB uploads folder (copies files directly instead of downloading).')
            ->addOption('include-hidden', null, InputOption::VALUE_NONE, 'Also migrate attachments still pending approval (visible = 0).')
            ->addOption('retry-failed', null, InputOption::VALUE_NONE, 'Try attachments previously recorded as failed again.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Idle timeout per request, in seconds: a download that keeps progressing is no longer killed.');
        $this->addMediaFetchOptions();
        $this->addMybbConnectionOptions();
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
        $retryFailed = (bool) $this->input->getOption('retry-failed');
        $includeHidden = (bool) $this->input->getOption('include-hidden');

        $limit = $this->intOpt('limit', (int) ($this->settings->get('mybb-migrator.image_limit') ?: 50));
        $maxMb = $this->intOpt('max-mb', (int) ($this->settings->get('mybb-migrator.image_max_mb') ?: 200));
        $maxFileMb = $this->intOpt('max-file-mb', (int) ($this->settings->get('mybb-migrator.image_max_file_mb') ?: 10));
        $timeout = $this->intOpt('timeout', 30) ?: 30;

        $uploadsDir = trim((string) ($this->input->getOption('uploads-dir')
            ?: (string) ($this->settings->get('mybb-migrator.attachments_dir') ?? '')));
        $oldSite = rtrim((string) ($this->settings->get('mybb-migrator.old_site_url') ?? ''), '/');

        // Sem pasta configurada, procuramos a do MyBB antes de cair no download
        // pela URL — cópia local é sempre mais confiável que attachment.php.
        if ($uploadsDir === '') {
            $found = $this->detector->detectUploadsDir();
            if ($found['path'] !== null) {
                $uploadsDir = $found['path'];
                $this->settings->set('mybb-migrator.attachments_dir', $uploadsDir);
                $this->info($this->trans('attachments.uploads_detected', ['path' => $uploadsDir]));
            } elseif ($found['checked'] !== []) {
                $this->info($this->trans('attachments.uploads_not_found', [
                    'paths' => implode(', ', array_slice($found['checked'], 0, 8)),
                ]));
            }
        }

        if ($uploadsDir !== '' && ! is_dir($uploadsDir)) {
            $this->error($this->trans('attachments.uploads_missing', ['path' => $uploadsDir]));

            return 1;
        }
        if ($uploadsDir === '' && $oldSite === '') {
            $this->error($this->trans('attachments.no_source'));

            return 1;
        }

        $maxBytes = max(1, $maxFileMb) * 1048576;
        $budgetBytes = $maxMb > 0 ? $maxMb * 1048576 : 0;
        $fetcher = $this->buildFetcher($this->settings, $timeout, $maxBytes);
        $optimizer = $this->buildOptimizer($this->settings);

        $this->info($this->trans('attachments.line_source', [
            'source' => $uploadsDir !== ''
                ? $this->trans('attachments.source_local', ['path' => $uploadsDir])
                : $this->trans('attachments.source_download', ['url' => $oldSite . '/attachment.php']),
        ]));
        if ($this->private->available()) {
            $this->info($this->trans('attachments.restricted', ['path' => $this->private->directoryHint()]));
        }
        $this->info($this->trans('common.line_limits', [
            'limits' => $this->trans($limit > 0 ? 'attachments.limit_files' : 'attachments.limit_files_none', ['count' => $limit])
                . ' / ' . $this->trans($maxMb > 0 ? 'common.limit_volume' : 'common.limit_volume_none', ['count' => $maxMb])
                . ' / ' . $this->trans('common.limit_per_file', ['count' => $maxFileMb]),
        ]));
        if ($uploadsDir === '') {
            $this->info($this->trans('common.line_network', ['summary' => $this->describeFetch($this->settings, $timeout)]));
        }
        $this->info($this->trans('common.line_optimize', [
            'summary' => $this->describeOptimizer($optimizer) . ' ' . $this->trans('attachments.optimize_images_only'),
        ]));

        if (! $this->store->uploadTableAvailable()) {
            $this->info($this->trans('attachments.no_upload_table'));
        }
        if ($dryRun) {
            $this->info($this->trans('common.dry_run'));
        }

        $mybb = $this->buildMybbDatabase($this->settings);
        $prefix = $mybb->prefix();

        $visibility = $includeHidden ? '' : 'WHERE visible = 1';

        // Anexos são uma coleção pequena e conhecida, então a barra do painel
        // pode ser percentual de verdade desde o primeiro item.
        $total = (int) $mybb->scalar("SELECT COUNT(*) FROM {$prefix}attachments {$visibility}");
        $this->steps->progress('attachments', 0, $total);

        $rows = $mybb->select(
            "SELECT aid, pid, uid, filename, filetype, filesize, attachname
             FROM {$prefix}attachments {$visibility}
             ORDER BY aid"
        );

        $map = $this->loadMap();

        while ($row = $rows->fetch()) {
            $aid = (int) $row['aid'];
            $pid = (int) $row['pid'];
            $this->seen++;
            $this->steps->progress('attachments', $this->seen, $total, "{$this->copied} migrados");

            $key = 'mybb:attachment:' . $aid;
            $hash = sha1($key);
            $known = $map[$hash] ?? null;

            $post = $this->db->table('posts')->select('id', 'user_id', 'discussion_id', 'content')->find($pid);
            if ($post === null) {
                $this->missingPost++;
                continue;
            }

            // Já migrado E já presente no post: nada a fazer.
            if ($known !== null && $known['status'] === 'ok') {
                // Reexecutar tem que consertar o que a execucao anterior deixou
                // pela metade: um registro que falhou na epoca (file_id nulo) ou
                // uma pivo que nunca foi criada deixam o arquivo sem discussao
                // conhecida, e o escopo por tag nao tem como classifica-lo.
                if (! $dryRun) {
                    $this->linkKnown($known, $pid);
                }

                if ($known['local_url'] !== null && str_contains((string) $post->content, (string) $known['local_url'])) {
                    $this->alreadyDone++;
                    continue;
                }

                if (! $dryRun) {
                    $this->appendToPost($post, (string) $known['local_url'], (string) $row['filename'], (string) $known['mime']);
                }
                $this->appended++;
                continue;
            }

            // Falha TRANSITÓRIA (429/timeout ao baixar do site antigo) volta
            // sozinha na execução seguinte; só o que morreu de verdade fica
            // congelado até alguém pedir --retry-failed.
            if ($known !== null && $known['status'] !== 'deferred' && ! $retryFailed) {
                continue;
            }

            // O limite conta TENTATIVAS: um lote de anexos inacessíveis custa o
            // mesmo tempo de rede que um lote que dá certo.
            if (($limit > 0 && $this->attempted >= $limit)
                || ($budgetBytes > 0 && $this->bytes >= $budgetBytes)) {
                $this->budgetHit = true;
                break;
            }

            $this->attempted++;

            if ($dryRun) {
                $this->copied++;
                $this->info($this->trans('attachments.dry_migrate', [
                    'aid'  => $aid,
                    'file' => $row['filename'],
                    'post' => $pid,
                ]));
                continue;
            }

            $transient = false;
            $bytes = $this->readAttachment($fetcher, $uploadsDir, $oldSite, $aid, (string) $row['attachname'], $maxBytes, $error, $transient);

            if ($bytes === null) {
                $transient ? $this->deferred++ : $this->failed++;
                $this->info($this->trans($transient ? 'attachments.deferred' : 'attachments.failed', [
                    'aid'   => $aid,
                    'file'  => $row['filename'],
                    'error' => $error ?? $this->trans('common.unknown_error'),
                ]));
                $this->remember($key, [
                    'status'    => $transient ? 'deferred' : 'failed',
                    'error'     => $error,
                    'local_url' => null,
                    'mime'      => null,
                ], $map);
                continue;
            }

            $read = strlen($bytes);
            $mime = $this->detectMime($bytes, (string) $row['filetype']);
            $ext = $this->extensionFor((string) $row['filename'], $mime);
            $baseName = (string) $row['filename'];

            // Anexo de imagem passa pelo mesmo re-encode das imagens de post; o
            // resto (zip, pdf, txt) segue byte a byte como estava.
            if (str_starts_with($mime, 'image/')) {
                $opt = $optimizer->optimize($bytes, $mime, $ext);
                if ($opt['changed']) {
                    $this->optimized++;
                    $this->savedBytes += $opt['saved'];
                    $this->info($this->trans('common.optimized', ['name' => 'aid=' . $aid, 'note' => $opt['note']]));
                    // O nome visível ao usuário acompanha o formato real: baixar
                    // um "foto.jpg" que é WebP por dentro confunde qualquer um.
                    $baseName = $this->renameExtension($baseName, $opt['ext']);
                }
                [$bytes, $mime, $ext] = [$opt['bytes'], $opt['mime'], $opt['ext']];
            }

            $name = $this->store->nameFor($key, $ext, 'mybb-att');

            // Mesmo princípio das imagens: o anexo de uma discussão restrita
            // nasce fora do document root, não é movido para lá depois.
            $private = $this->private->shouldBePrivate((int) $post->discussion_id);

            if ($this->store->exists($name)) {
                $private = null; // já existia: o flush do fim reclassifica
                $localUrl = $this->store->urlFor($name);
            } else {
                $localUrl = $this->store->put($name, $bytes, $private);
                if ($private) {
                    $this->privateWrites++;
                }
            }

            $fileId = $this->store->registerUploadFile(
                $name,
                $localUrl,
                $mime,
                strlen($bytes),
                $this->flarumUserId((int) ($row['uid'] ?? 0)),
                $pid,
                (int) $post->discussion_id,
                $baseName,
                $bytes,
            );

            if ($private === true) {
                $this->private->markPrivate((int) $fileId);
            }

            $this->copied++;
            // Orçamento do run mede o que foi LIDO da origem, não o que sobrou
            // depois de otimizar.
            $this->bytes += $read;
            $this->visibility->touch($fileId);

            $this->remember($key, [
                'status'     => 'ok',
                'local_name' => $name,
                'local_url'  => $localUrl,
                'size'       => strlen($bytes),
                'mime'       => $mime,
                'file_id'    => $fileId,
                'error'      => null,
            ], $map);

            $this->appendToPost($post, $localUrl, $baseName, $mime);
            $this->appended++;
        }

        $this->steps->progress('attachments', $this->seen, $total);

        if (! $dryRun && ($moved = $this->visibility->flush()) > 0) {
            $this->info($this->trans('attachments.moved_private', ['count' => $moved]));
        }

        $this->report($dryRun);

        return 0;
    }

    /**
     * Garante o vinculo arquivo -> post de um anexo ja migrado, resolvendo o id
     * pelo mapa ou pelo nome do arquivo.
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
     * Acrescenta o anexo ao fim do post. Usa unparse → texto → parse (o mesmo
     * caminho de uma edição no Flarum), então o XML resultante é sempre válido,
     * inclusive quando o post estava como texto puro (`<t>`).
     */
    private function appendToPost(object $post, string $url, string $filename, string $mime): void
    {
        $xml = (string) $post->content;

        try {
            $text = (string) $this->formatter->unparse($xml);
        } catch (\Throwable $e) {
            $this->error($this->trans('attachments.unparse_failed', ['post' => $post->id, 'error' => $e->getMessage()]));

            return;
        }

        $markup = str_starts_with($mime, 'image/')
            ? '[img]' . $url . '[/img]'
            : '[url=' . $url . ']' . ($filename !== '' ? $filename : $url) . '[/url]';

        $newText = rtrim($text) . "\n\n" . $markup;

        try {
            $newXml = $this->formatter->parse($newText);
        } catch (\Throwable $e) {
            $this->error($this->trans('attachments.parse_failed', ['post' => $post->id, 'error' => $e->getMessage()]));

            return;
        }

        $this->db->table('posts')->where('id', $post->id)->update(['content' => $newXml]);
        $post->content = $newXml;
    }

    /**
     * Bytes do anexo: cópia local quando há pasta de uploads, download via
     * `attachment.php` caso contrário.
     */
    private function readAttachment(
        ImageFetcher $fetcher,
        string $uploadsDir,
        string $oldSite,
        int $aid,
        string $attachName,
        int $maxBytes,
        ?string &$error,
        bool &$transient = false,
    ): ?string {
        $error = null;
        $transient = false;

        if ($uploadsDir !== '') {
            // O MyBB guarda os anexos em subpastas por mês (ex.:
            // `201506/post_29_....attach`), então o caminho relativo é legítimo —
            // o que não pode é escapar da pasta de uploads.
            $relative = ltrim(str_replace('\\', '/', $attachName), '/');
            if ($relative === ''
                || str_contains($relative, '..')
                || preg_match('#^[a-z]:/#i', $relative)) {
                $error = "attachname suspeito: {$attachName}";

                return null;
            }

            $path = rtrim($uploadsDir, '/\\') . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (! is_file($path)) {
                $error = "arquivo ausente em disco: {$path}";

                return null;
            }
            if (filesize($path) > $maxBytes) {
                $error = 'excede o limite por arquivo';

                return null;
            }

            $bytes = @file_get_contents($path);
            if ($bytes === false || $bytes === '') {
                $error = 'não foi possível ler o arquivo';

                return null;
            }

            return $bytes;
        }

        if ($oldSite === '') {
            $error = 'sem pasta de uploads e sem URL do site antigo';

            return null;
        }

        $res = $fetcher->fetchFile($oldSite . '/attachment.php?aid=' . $aid);
        if (! $res['ok']) {
            $error = (string) $res['error'];
            $transient = (bool) ($res['transient'] ?? false);

            return null;
        }

        return (string) $res['bytes'];
    }

    private function detectMime(string $bytes, string $declared): string
    {
        if (function_exists('finfo_buffer')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = @finfo_buffer($finfo, $bytes);
                if (is_string($mime) && $mime !== '') {
                    return strtolower($mime);
                }
            }
        }

        $declared = strtolower(trim($declared));

        return $declared !== '' ? $declared : 'application/octet-stream';
    }

    /**
     * Troca a extensão do nome ORIGINAL do anexo pelo formato realmente gravado
     * (`ferias.jpg` -> `ferias.webp`), preservando o resto do nome — é ele que o
     * usuário vê no gerenciador de mídia e no download.
     */
    private function renameExtension(string $filename, string $ext): string
    {
        $stem = (string) pathinfo($filename, PATHINFO_FILENAME);

        return ($stem !== '' ? $stem : 'anexo') . '.' . $ext;
    }

    private function extensionFor(string $filename, string $mime): string
    {
        $fromName = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if (preg_match('/^[a-z0-9]{1,8}$/', $fromName)) {
            return $fromName;
        }

        return ImageFetcher::extensionFor($mime) ?? 'bin';
    }

    /** O migrador preserva uid = id; confirma que o usuário existe no destino. */
    private function flarumUserId(int $uid): ?int
    {
        if ($uid <= 0) {
            return null;
        }

        return $this->db->table('users')->where('id', $uid)->exists() ? $uid : null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, array<string, mixed>> $map
     */
    private function remember(string $key, array $data, array &$map): void
    {
        $hash = sha1($key);

        $row = array_merge([
            'url_hash'   => $hash,
            'source_url' => $key,
            'kind'       => 'attachment',
            'created_at' => date('Y-m-d H:i:s'),
        ], $data);

        try {
            $this->db->table('mybb_migrated_images')->updateOrInsert(['url_hash' => $hash], $row);
        } catch (\Throwable $e) {
            $this->error($this->trans('common.map_write_failed', ['key' => $key, 'error' => $e->getMessage()]));
        }

        $map[$hash] = [
            'status'    => (string) ($data['status'] ?? 'ok'),
            'local_url' => $data['local_url'] ?? null,
            'mime'      => $data['mime'] ?? null,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadMap(): array
    {
        $map = [];

        try {
            $rows = $this->db->table('mybb_migrated_images')
                ->where('kind', 'attachment')
                ->select('url_hash', 'status', 'local_url', 'mime')
                ->cursor();

            foreach ($rows as $row) {
                $map[(string) $row->url_hash] = [
                    'status'    => (string) $row->status,
                    'local_url' => $row->local_url,
                    'mime'      => $row->mime,
                ];
            }
        } catch (\Throwable $e) {
            $this->error($this->trans('common.map_missing', ['error' => $e->getMessage()]));
        }

        return $map;
    }

    private function intOpt(string $name, int $default): int
    {
        $value = $this->input->getOption($name);

        return $value === null || $value === '' ? $default : max(0, (int) $value);
    }

    private function report(bool $dryRun): void
    {
        $this->info($this->trans($dryRun ? 'common.dry_run_done' : 'common.done'));
        $this->stat('attachments.stats.seen', $this->seen);
        $this->stat('attachments.stats.copied', $this->copied);
        $this->stat('attachments.stats.private', $this->privateWrites);
        $this->stat('attachments.stats.appended', $this->appended);
        $this->stat('attachments.stats.already', $this->alreadyDone);
        $this->stat('attachments.stats.missing_post', $this->missingPost);
        $this->stat('attachments.stats.mb', round($this->bytes / 1048576, 2));
        $this->stat('common.stats.optimized', $this->optimized);
        $this->stat('common.stats.saved', round($this->savedBytes / 1048576, 2));
        $this->stat('common.stats.failed', $this->failed);
        $this->stat('common.stats.deferred', $this->deferred);

        if ($this->budgetHit) {
            $this->info($this->trans('attachments.budget_hit'));
        }

        if ($this->failed > 0) {
            // A falha típica é ambiental (o MyBB exige login para baixar), não
            // permanente — mas a linha fica marcada como `failed`. Dizer como
            // destravar evita a impressão de que o anexo é irrecuperável.
            $this->info($this->trans('attachments.failed_hint'));
        }

        if ($this->deferred > 0) {
            $this->info($this->trans('attachments.deferred_hint', ['count' => $this->deferred]));
        }
    }
}
