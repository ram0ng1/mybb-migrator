<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Foundation\Paths;
use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\Gui\StepStore;
use Ramon\MybbMigrator\Support\ImageOptimizer;
use Ramon\MybbMigrator\Support\ImageStore;
use Symfony\Component\Console\Input\InputOption;

/**
 * Re-encoda o que JÁ foi internalizado.
 *
 * `mybb:images` e `mybb:attachments` otimizam na entrada, mas isso só vale para
 * o que ainda vai ser baixado. Quem migrou antes de a otimização existir (ou
 * rodou com `--no-optimize`) tem no disco os originais de câmera — 2 MB para
 * exibir a 700 px. Este passo passa por cima do acervo local e conserta isso.
 *
 * A troca é ATÔMICA por arquivo e nesta ordem, porque o que não pode acontecer é
 * um post apontando para um arquivo que não existe mais:
 *
 *   1. grava o arquivo NOVO (o antigo continua lá, ninguém perde nada);
 *   2. reaponta a linha de `fof_upload_files` (gerenciador de mídia);
 *   3. reescreve os posts — troca do NOME do arquivo dentro do XML, que é único
 *      e sem caracteres especiais, então pega de uma vez o `src` do `<IMG>` e o
 *      token `[img]` que o s9e guarda ao lado;
 *   4. atualiza o mapa `mybb_migrated_images`;
 *   5. só então apaga o arquivo antigo.
 *
 * Arquivo que o otimizador recusa (GIF animado, SVG, AVIF, resultado que não
 * ficou menor) é deixado exatamente como está — ver ImageOptimizer.
 */
class OptimizeMediaCommand extends AbstractCommand
{
    use MediaFetchOptions;
    use TranslatesOutput;

    private int $seen = 0;
    private int $optimized = 0;
    private int $renamed = 0;
    private int $postsUpdated = 0;
    private int $skipped = 0;
    private int $missing = 0;
    private int $failed = 0;
    private int $before = 0;
    private int $after = 0;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected ImageStore $store,
        protected Paths $paths,
        protected StepStore $steps,
        protected LocaleManager $locales,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:optimize-media')
            ->setDescription('Re-encodes images already localized by mybb:images / mybb:attachments (WebP, resize) and repoints the posts at the new files.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report only: nothing is written, renamed or deleted.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum files to process in this run (0 = all).')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Which map entries to walk: image, attachment or all (default all).')
            ->addOption('keep-old', null, InputOption::VALUE_NONE, 'Do not delete the pre-optimization file after repointing.');

        // --quality / --max-dim / --no-webp / --no-optimize vêm do trait, com os
        // mesmos padrões da aba "Imagens" — o resultado aqui e na importação é
        // exatamente o mesmo arquivo.
        $this->addMediaFetchOptions(network: false);
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
        $keepOld = (bool) $this->input->getOption('keep-old');
        $limit = max(0, (int) $this->input->getOption('limit'));
        $kind = strtolower(trim((string) ($this->input->getOption('kind') ?? 'all')));

        $optimizer = $this->buildOptimizer($this->settings);

        if (! $optimizer->available()) {
            $this->error($this->trans('optimize.unavailable'));

            return 1;
        }

        $this->info($this->trans('common.line_optimize', ['summary' => $this->describeOptimizer($optimizer)]));
        $this->info($this->trans('common.line_scope', [
            'scope' => $this->trans('optimize.kind_' . ($kind === 'image' || $kind === 'attachment' ? $kind : 'all'))
                . ($limit > 0 ? ' ' . $this->trans('optimize.scope_limit', ['count' => $limit]) : ''),
        ]));
        if ($dryRun) {
            $this->info($this->trans('optimize.dry_run'));
        }

        $query = $this->db->table('mybb_migrated_images')
            ->select('id', 'source_url', 'local_name', 'mime', 'kind', 'size', 'file_id')
            ->where('status', 'ok')
            ->whereNotNull('local_name');

        if ($kind === 'image' || $kind === 'attachment') {
            $query->where('kind', $kind);
        }

        $total = (clone $query)->count();
        $this->info($this->trans('optimize.total', ['count' => $total]));
        $this->steps->progress('optimize-media', 0, $total);

        foreach ($query->orderBy('id')->cursor() as $row) {
            if ($limit > 0 && $this->seen >= $limit) {
                break;
            }

            $this->seen++;
            $this->handle($row, $optimizer, $dryRun, $keepOld);

            if ($this->seen % 20 === 0) {
                $this->steps->progress(
                    'optimize-media',
                    $this->seen,
                    $total,
                    $this->trans('optimize.progress', ['count' => $this->optimized])
                );
            }
        }

        $this->steps->progress('optimize-media', $this->seen, $total);
        $this->report($dryRun);

        return 0;
    }

    /**
     * Um arquivo do mapa: lê, otimiza e — se ganhou — troca tudo que aponta
     * para ele.
     */
    private function handle(object $row, ImageOptimizer $optimizer, bool $dryRun, bool $keepOld): void
    {
        $name = (string) $row->local_name;
        $bytes = $this->store->read($name);

        if ($bytes === null) {
            $this->missing++;

            return;
        }

        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $result = $optimizer->optimize($bytes, (string) $row->mime, $ext);

        if (! $result['changed']) {
            $this->skipped++;

            return;
        }

        $prefix = (string) $row->kind === 'attachment' ? 'mybb-att' : 'mybb';
        $newName = $this->store->nameFor((string) $row->source_url, $result['ext'], $prefix);

        $this->before += strlen($bytes);
        $this->after += strlen($result['bytes']);
        $this->optimized++;
        $this->info($this->trans('common.optimized', ['name' => $name, 'note' => $result['note']]));

        if ($dryRun) {
            if ($newName !== $name) {
                $this->renamed++;
            }

            return;
        }

        try {
            // 1. o arquivo novo nasce ANTES de qualquer referência mudar, e do
            //    mesmo lado (público/privado) em que o antigo estava.
            $private = $this->store->storedPrivately($name);
            $this->store->put($newName, $result['bytes'], $private);
            $url = $this->store->urlFor($newName);

            // 2 e 4. gerenciador de mídia e mapa.
            $this->store->repointUploadFile(
                $name,
                $newName,
                $url,
                $result['mime'],
                strlen($result['bytes']),
                $newName === $name ? null : $this->renameBase($name, $result['ext']),
                $result['bytes'],
            );

            // 3. posts: só quando o nome mudou (mesma extensão = mesmo arquivo,
            //    e o conteúdo do post já aponta para ele).
            if ($newName !== $name) {
                $this->renamed++;
                $this->postsUpdated += $this->rewritePosts($name, $newName);
            }

            $this->db->table('mybb_migrated_images')->where('id', $row->id)->update([
                'local_name' => $newName,
                'local_url'  => $url,
                'size'       => strlen($result['bytes']),
                'mime'       => $result['mime'],
            ]);

            // 5. o antigo só some depois que nada mais aponta para ele.
            if ($newName !== $name && ! $keepOld) {
                $this->store->delete($name);
            }
        } catch (\Throwable $e) {
            $this->failed++;
            $this->error($this->trans('optimize.failed', ['name' => $name, 'error' => $e->getMessage()]));
        }
    }

    /**
     * Troca o nome do arquivo dentro do XML dos posts.
     *
     * O nome é `mybb-<sha1>.<ext>`: único no fórum inteiro e sem nenhum
     * caractere que o XML escape, então um REPLACE literal atinge o `src` do
     * `<IMG>` e o token `[img]` de uma vez, sem desmontar o conteúdo.
     */
    private function rewritePosts(string $oldName, string $newName): int
    {
        // Interpolar num REPLACE() cru só é aceitável porque o nome é gerado por
        // nós (`mybb-<sha1>.<ext>`); qualquer coisa fora desse alfabeto é bug, e
        // vira exceção em vez de SQL.
        foreach ([$oldName, $newName] as $candidate) {
            if (! preg_match('/^[A-Za-z0-9._-]+$/', $candidate)) {
                throw new \RuntimeException("nome de arquivo inesperado: {$candidate}");
            }
        }

        return (int) $this->db->table('posts')
            ->where('content', 'like', '%' . $oldName . '%')
            ->update(['content' => $this->db->raw("REPLACE(content, '{$oldName}', '{$newName}')")]);
    }

    /**
     * Nome VISÍVEL do arquivo no gerenciador de mídia (`ferias.jpg` ->
     * `ferias.webp`): baixar um "ferias.jpg" que é WebP por dentro confunde
     * qualquer um. Sem linha (fof/upload ausente) não há o que renomear.
     */
    private function renameBase(string $oldName, string $ext): ?string
    {
        try {
            $current = (string) $this->db->table('fof_upload_files')
                ->where('path', $oldName)
                ->value('base_name');
        } catch (\Throwable $e) {
            return null;
        }

        if ($current === '') {
            return null;
        }

        $stem = (string) pathinfo($current, PATHINFO_FILENAME);

        return ($stem !== '' ? $stem : 'arquivo') . '.' . $ext;
    }

    private function report(bool $dryRun): void
    {
        $saved = max(0, $this->before - $this->after);
        $pct = $this->before > 0 ? round(100 * $saved / $this->before) : 0;

        $this->info($this->trans($dryRun ? 'common.dry_run_done' : 'common.done'));
        $this->stat('optimize.stats.seen', $this->seen);
        $this->stat('optimize.stats.optimized', $this->optimized);
        $this->stat('optimize.stats.renamed', $this->renamed);
        $this->stat('optimize.stats.posts', $this->postsUpdated);
        $this->stat('optimize.stats.skipped', $this->skipped);
        $this->stat('optimize.stats.missing', $this->missing);
        $this->stat('optimize.stats.failed', $this->failed);
        $this->stat('optimize.stats.before', round($this->before / 1048576, 2));
        $this->stat('optimize.stats.after', round($this->after / 1048576, 2));
        $this->stat('optimize.stats.saved', round($saved / 1048576, 2) . " MB ({$pct}%)");

        if ($this->missing > 0) {
            $this->info($this->trans('optimize.missing_hint'));
        }
    }
}
