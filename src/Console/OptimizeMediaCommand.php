<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Foundation\Paths;
use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\Gui\StepStore;
use Ramon\MybbMigrator\Support\ImageOptimizer;
use Ramon\MybbMigrator\Support\ImageStore;
use Symfony\Component\Console\Input\InputOption;

/**
 * Re-encoda o que JÁ está no disco deste Flarum.
 *
 * `mybb:images` e `mybb:attachments` otimizam na ENTRADA, o que só vale para o
 * que ainda vai ser baixado. Este comando é a outra metade: passa por cima do
 * acervo local e conserta o que já está lá — inclusive o que nunca passou por
 * esta extensão.
 *
 * Três varreduras, ligadas separadamente porque cada uma reaponta um lugar
 * diferente:
 *
 *  1. MAPA (`mybb_migrated_images`) — imagens e anexos que esta extensão
 *     internalizou. Reaponta `fof_upload_files` e o XML dos posts.
 *  2. ÓRFÃOS (`--include-orphans`) — arquivos que estão em `assets/files` (ou no
 *     armazenamento privado) sem linha no mapa: uploads de verdade, execuções
 *     antigas, arquivos adotados. Reaponta `fof_upload_files` e os posts.
 *  3. AVATARES (`--include-avatars`) — `assets/avatars`, que não passa nem pelo
 *     mapa nem pelo fof/upload; num fórum migrado é de longe a maior pilha de
 *     JPEG que sobra. Reaponta `users.avatar_url`.
 *
 * `--all` liga as três. É o que o passo "otimizar TODAS as imagens" do painel
 * usa: uma passada só, sem limite, sobre tudo que existe.
 *
 * A troca é ATÔMICA por arquivo e sempre nesta ordem, porque o que não pode
 * acontecer é uma referência apontando para um arquivo que já não existe:
 *
 *   1. grava o arquivo NOVO (o antigo continua lá, ninguém perde nada);
 *   2. reaponta o registro (`fof_upload_files` / `users.avatar_url`);
 *   3. reescreve os posts;
 *   4. atualiza o mapa, quando existe linha;
 *   5. só então apaga o arquivo antigo (`--keep-old` pula esta etapa).
 *
 * Reexecutar é inofensivo: arquivo já otimizado não rende o ganho mínimo e volta
 * intacto do ImageOptimizer — que também recusa GIF animado, SVG e AVIF.
 */
class OptimizeMediaCommand extends AbstractCommand
{
    use MediaFetchOptions;
    use TranslatesOutput;

    private int $seen = 0;
    private int $optimized = 0;
    private int $renamed = 0;
    private int $postsUpdated = 0;
    private int $avatarsUpdated = 0;
    private int $skipped = 0;
    private int $missing = 0;
    private int $failed = 0;
    private int $before = 0;
    private int $after = 0;

    /** --limit atingido: para de varrer, inclusive as varreduras seguintes. */
    private bool $exhausted = false;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected ImageStore $store,
        protected FilesystemFactory $filesystem,
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
            ->setDescription('Re-encodes images already stored by this Flarum (WebP, resize) and repoints posts, the media manager and avatars at the new files.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report only: nothing is written, renamed or deleted.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum files to process in this run (0 = all).')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Which map entries to walk: image, attachment or all (default all).')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every image in this Flarum: the map, files on disk with no map row, and avatars.')
            ->addOption('include-orphans', null, InputOption::VALUE_NONE, 'Also walk files on disk that have no row in the migration map.')
            ->addOption('include-avatars', null, InputOption::VALUE_NONE, 'Also walk assets/avatars and repoint users.avatar_url.')
            ->addOption('skip-map', null, InputOption::VALUE_NONE, 'Skip the migration map (e.g. to only touch avatars).')
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

        $all = (bool) $this->input->getOption('all');
        $withMap = ! $this->input->getOption('skip-map');
        $withOrphans = $all || (bool) $this->input->getOption('include-orphans');
        $withAvatars = $all || (bool) $this->input->getOption('include-avatars');

        $optimizer = $this->buildOptimizer($this->settings);

        if (! $optimizer->available()) {
            $this->error($this->trans('optimize.unavailable'));

            return 1;
        }

        $this->info($this->trans('common.line_optimize', ['summary' => $this->describeOptimizer($optimizer)]));
        $this->info($this->trans('common.line_scope', [
            'scope' => $this->describeScope($withMap, $kind, $withOrphans, $withAvatars, $limit),
        ]));
        if ($dryRun) {
            $this->info($this->trans('optimize.dry_run'));
        }

        // O total é a soma das varreduras ligadas: com ele a barra do painel é
        // percentual de verdade, e o admin vê de cara o tamanho do trabalho.
        $orphans = $withOrphans ? $this->orphanNames($this->mapNames('all')) : [];
        // Avatares são agrupados por FAMÍLIA (base + @2x + @3x): o srcset do
        // Flarum deriva o caminho das variantes a partir do arquivo base, então
        // elas têm que trocar de formato juntas ou nenhuma.
        $avatars = $withAvatars ? $this->avatarFamilies() : [];
        $mapRows = $withMap ? $this->mapCount($kind) : 0;

        $total = $mapRows + count($orphans) + count($avatars);
        if ($limit > 0) {
            $total = min($total, $limit);
        }

        $this->info($this->trans('optimize.total', ['count' => $total]));
        $this->steps->progress('optimize-media', 0, $total);

        if ($withMap) {
            foreach ($this->mapQuery($kind)->orderBy('id')->cursor() as $row) {
                if ($this->stop($limit)) {
                    break;
                }
                $this->handleFile((string) $row->local_name, $optimizer, $dryRun, $keepOld, $row);
                $this->tick($total);
            }
        }

        if ($withOrphans && ! $this->exhausted) {
            $this->info($this->trans('optimize.sweep_orphans', ['count' => count($orphans)]));
            foreach ($orphans as $name) {
                if ($this->stop($limit)) {
                    break;
                }
                $this->handleFile($name, $optimizer, $dryRun, $keepOld, null);
                $this->tick($total);
            }
        }

        if ($withAvatars && ! $this->exhausted) {
            $this->info($this->trans('optimize.sweep_avatars', ['count' => count($avatars)]));
            foreach ($avatars as $parts) {
                if ($this->stop($limit)) {
                    break;
                }
                $this->handleAvatarFamily($parts, $optimizer, $dryRun, $keepOld);
                $this->tick($total);
            }
        }

        $this->steps->progress('optimize-media', $this->seen, $total);
        $this->report($dryRun, $withAvatars);

        return 0;
    }

    /**
     * Um arquivo do armazenamento (com ou sem linha no mapa): lê, otimiza e — se
     * ganhou — troca tudo que aponta para ele.
     */
    private function handleFile(string $name, ImageOptimizer $optimizer, bool $dryRun, bool $keepOld, ?object $row): void
    {
        $this->seen++;

        $bytes = $this->store->read($name);
        if ($bytes === null) {
            $this->missing++;

            return;
        }

        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $result = $optimizer->optimize($bytes, $row?->mime, $ext);

        if (! $result['changed']) {
            $this->skipped++;

            return;
        }

        // Com linha no mapa o nome é derivado da URL de origem (determinístico,
        // igual ao que a importação geraria); sem ela, mantemos o nome do arquivo
        // e trocamos só a extensão.
        $newName = $row !== null
            ? $this->store->nameFor(
                (string) $row->source_url,
                $result['ext'],
                (string) $row->kind === 'attachment' ? 'mybb-att' : 'mybb'
            )
            : $this->swapExtension($name, $result['ext']);

        $this->account($bytes, $result);
        $this->info($this->trans('common.optimized', ['name' => $name, 'note' => $result['note']]));

        if ($dryRun) {
            if ($newName !== $name) {
                $this->renamed++;
            }

            return;
        }

        // Nome novo já ocupado por OUTRO arquivo (o mesmo stem em dois formatos):
        // sobrescrever perderia o outro. Deixa como está.
        if ($newName !== $name && $row === null && $this->store->exists($newName)) {
            $this->skipped++;

            return;
        }

        try {
            // 1. o arquivo novo nasce ANTES de qualquer referência mudar, e do
            //    mesmo lado (público/privado) em que o antigo estava.
            $private = $this->store->storedPrivately($name);
            $this->store->put($newName, $result['bytes'], $private);
            $url = $this->store->urlFor($newName);

            // 2. gerenciador de mídia.
            $this->store->repointUploadFile(
                $name,
                $newName,
                $url,
                $result['mime'],
                strlen($result['bytes']),
                $newName === $name ? null : $this->renameBase($name, $result['ext']),
                $result['bytes'],
            );

            // 3. posts: só quando o nome mudou (mesma extensão = mesmo arquivo, e
            //    o conteúdo do post já aponta para ele).
            if ($newName !== $name) {
                $this->renamed++;
                $this->postsUpdated += $this->rewritePosts($name, $newName);
            }

            // 4. mapa, quando existe linha.
            if ($row !== null) {
                $this->db->table('mybb_migrated_images')->where('id', $row->id)->update([
                    'local_name' => $newName,
                    'local_url'  => $url,
                    'size'       => strlen($result['bytes']),
                    'mime'       => $result['mime'],
                ]);
            }

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
     * Uma família de avatar: o arquivo base e suas variantes de alta densidade.
     *
     * O registro fica em `users.avatar_url` (só o nome do arquivo base), fora do
     * mapa e fora do fof/upload — num fórum migrado é aqui que mora a maior
     * pilha de JPEG. E o `srcset` do core DERIVA `X@2x.<ext>` do nome do base,
     * então converter o base sem converter as variantes serviria 404 para telas
     * retina: ou a família inteira vira WebP, ou nada nela é tocado.
     *
     * @param array<string, string> $parts sufixo ('', '@2x', '@3x') => nome do arquivo
     */
    private function handleAvatarFamily(array $parts, ImageOptimizer $optimizer, bool $dryRun, bool $keepOld): void
    {
        $this->seen++;

        $name = $parts[''] ?? null;
        if ($name === null) {
            // Variante sem base: inalcançável pelo fórum, não vale reescrever.
            $this->skipped++;

            return;
        }

        $disk = $this->avatarDisk();
        $bytes = $this->readAvatar($name);

        if ($bytes === null) {
            $this->missing++;

            return;
        }

        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $result = $optimizer->optimize($bytes, null, $ext);
        $targetExt = $result['changed'] ? $result['ext'] : $ext;

        // Variantes que ainda não estão no formato do base — inclusive quando o
        // base não mudou nada nesta execução (conserta família desalinhada).
        $pending = [];
        foreach ($parts as $suffix => $variant) {
            if ($suffix === '' || strtolower((string) pathinfo($variant, PATHINFO_EXTENSION)) === $targetExt) {
                continue;
            }

            $variantBytes = $this->readAvatar($variant);
            if ($variantBytes === null) {
                continue; // variante ilegível: some do srcset de qualquer jeito
            }

            $converted = $optimizer->optimize(
                $variantBytes,
                null,
                strtolower((string) pathinfo($variant, PATHINFO_EXTENSION)),
                force: true
            );

            // Não conseguiu acompanhar (animada, formato recusado): então o base
            // também não muda — família consistente vale mais que bytes.
            if ($converted['ext'] !== $targetExt) {
                $this->skipped++;

                return;
            }

            $pending[$variant] = $converted;
        }

        if (! $result['changed'] && $pending === []) {
            $this->skipped++;

            return;
        }

        if ($result['changed']) {
            $this->account($bytes, $result);
            $this->info($this->trans('common.optimized', ['name' => $name, 'note' => $result['note']]));
        }

        $newName = $result['changed'] ? $this->swapExtension($name, $targetExt) : $name;

        if ($dryRun) {
            if ($newName !== $name) {
                $this->renamed++;
            }

            return;
        }

        try {
            if ($newName !== $name && $disk->exists($newName)) {
                $this->skipped++;

                return;
            }

            if ($result['changed']) {
                $disk->put($newName, $result['bytes']);
            }

            foreach ($pending as $variant => $converted) {
                $variantName = $this->swapExtension($variant, $targetExt);
                $disk->put($variantName, $converted['bytes']);

                if ($variantName !== $variant && ! $keepOld) {
                    $disk->delete($variant);
                }
            }

            if ($newName !== $name) {
                $this->renamed++;
                $this->avatarsUpdated += (int) $this->db->table('users')
                    ->where('avatar_url', $name)
                    ->update(['avatar_url' => $newName]);

                if (! $keepOld) {
                    $disk->delete($name);
                }
            }
        } catch (\Throwable $e) {
            $this->failed++;
            $this->error($this->trans('optimize.failed', ['name' => $name, 'error' => $e->getMessage()]));
        }
    }

    private function readAvatar(string $name): ?string
    {
        try {
            $bytes = (string) $this->avatarDisk()->get($name);
        } catch (\Throwable $e) {
            return null;
        }

        return $bytes === '' ? null : $bytes;
    }

    /**
     * Troca o nome do arquivo dentro do XML dos posts.
     *
     * A busca é pelo caminho `files/<nome>`, não pelo nome solto: um arquivo de
     * upload pode se chamar `1.jpg`, e trocar essa string dentro do conteúdo dos
     * posts estragaria texto. Com o diretório na frente o casamento é inequívoco
     * — e continua pegando de uma vez o `src` do `<IMG>` e o token `[img]` que o
     * s9e guarda ao lado.
     */
    private function rewritePosts(string $oldName, string $newName): int
    {
        // Interpolar num REPLACE() cru só é aceitável com nome de arquivo
        // sanitizado; qualquer coisa fora deste alfabeto é bug, e vira exceção
        // em vez de SQL.
        foreach ([$oldName, $newName] as $candidate) {
            if (! preg_match('/^[A-Za-z0-9._-]+$/', $candidate)) {
                throw new \RuntimeException("nome de arquivo inesperado: {$candidate}");
            }
        }

        $old = ImageStore::DIR . '/' . $oldName;
        $new = ImageStore::DIR . '/' . $newName;

        return (int) $this->db->table('posts')
            ->where('content', 'like', '%' . $old . '%')
            ->update(['content' => $this->db->raw("REPLACE(content, '{$old}', '{$new}')")]);
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

        return $this->swapExtension($current, $ext);
    }

    private function swapExtension(string $name, string $ext): string
    {
        $stem = (string) pathinfo($name, PATHINFO_FILENAME);

        return ($stem !== '' ? $stem : 'file') . '.' . $ext;
    }

    /**
     * Arquivos do armazenamento que não têm linha no mapa.
     *
     * @param array<int, string> $mapNames
     * @return array<int, string>
     */
    private function orphanNames(array $mapNames): array
    {
        $known = array_flip($mapNames);

        return array_values(array_filter(
            $this->store->listStoredNames(),
            fn (string $name) => ! isset($known[$name])
        ));
    }

    /**
     * Avatares agrupados por família: `X.jpg`, `X@2x.jpg` e `X@3x.jpg` são UM
     * item, porque trocam de formato juntos (ver handleAvatarFamily).
     *
     * @return array<string, array<string, string>> stem => (sufixo => arquivo)
     */
    private function avatarFamilies(): array
    {
        try {
            $files = $this->avatarDisk()->files();
        } catch (\Throwable $e) {
            return [];
        }

        $families = [];

        foreach ($files as $path) {
            $name = basename((string) $path);

            // A pasta de avatares também guarda o index.html de proteção.
            if ($name === '' || str_starts_with($name, '.') || str_ends_with(strtolower($name), '.html')) {
                continue;
            }

            $stem = (string) pathinfo($name, PATHINFO_FILENAME);
            $suffix = '';

            if (preg_match('/^(.*)(@[23]x)$/', $stem, $m)) {
                [$stem, $suffix] = [$m[1], $m[2]];
            }

            $families[$stem][$suffix] = $name;
        }

        return $families;
    }

    /**
     * @return array<int, string>
     */
    private function mapNames(string $kind): array
    {
        return array_map('strval', $this->mapQuery($kind)->pluck('local_name')->all());
    }

    private function mapCount(string $kind): int
    {
        return (int) $this->mapQuery($kind)->count();
    }

    private function mapQuery(string $kind): \Illuminate\Database\Query\Builder
    {
        $query = $this->db->table('mybb_migrated_images')
            ->select('id', 'source_url', 'local_name', 'mime', 'kind')
            ->where('status', 'ok')
            ->whereNotNull('local_name');

        if ($kind === 'image' || $kind === 'attachment') {
            $query->where('kind', $kind);
        }

        return $query;
    }

    private function avatarDisk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return $this->filesystem->disk('flarum-avatars');
    }

    /**
     * @param array{bytes: string, saved: int} $result
     */
    private function account(string $before, array $result): void
    {
        $this->optimized++;
        $this->before += strlen($before);
        $this->after += strlen($result['bytes']);
    }

    private function stop(int $limit): bool
    {
        if ($limit > 0 && $this->seen >= $limit) {
            $this->exhausted = true;
        }

        return $this->exhausted;
    }

    private function tick(int $total): void
    {
        if ($this->seen % 20 === 0) {
            $this->steps->progress(
                'optimize-media',
                $this->seen,
                $total,
                $this->trans('optimize.progress', ['count' => $this->optimized])
            );
        }
    }

    private function describeScope(bool $withMap, string $kind, bool $withOrphans, bool $withAvatars, int $limit): string
    {
        $parts = [];

        if ($withMap) {
            $parts[] = $this->trans('optimize.kind_' . ($kind === 'image' || $kind === 'attachment' ? $kind : 'all'));
        }
        if ($withOrphans) {
            $parts[] = $this->trans('optimize.scope_orphans');
        }
        if ($withAvatars) {
            $parts[] = $this->trans('optimize.scope_avatars');
        }

        return implode(' + ', $parts)
            . ($limit > 0 ? ' ' . $this->trans('optimize.scope_limit', ['count' => $limit]) : '');
    }

    private function report(bool $dryRun, bool $withAvatars): void
    {
        $saved = max(0, $this->before - $this->after);
        $pct = $this->before > 0 ? round(100 * $saved / $this->before) : 0;

        $this->info($this->trans($dryRun ? 'common.dry_run_done' : 'common.done'));
        $this->stat('optimize.stats.seen', $this->seen);
        $this->stat('optimize.stats.optimized', $this->optimized);
        $this->stat('optimize.stats.renamed', $this->renamed);
        $this->stat('optimize.stats.posts', $this->postsUpdated);
        if ($withAvatars) {
            $this->stat('optimize.stats.avatars', $this->avatarsUpdated);
        }
        $this->stat('optimize.stats.skipped', $this->skipped);
        $this->stat('optimize.stats.missing', $this->missing);
        $this->stat('optimize.stats.failed', $this->failed);
        $this->stat('optimize.stats.before', round($this->before / 1048576, 2));
        $this->stat('optimize.stats.after', round($this->after / 1048576, 2));
        $this->stat('optimize.stats.saved', round($saved / 1048576, 2) . " MB ({$pct}%)");

        if ($this->exhausted) {
            $this->info($this->trans('optimize.limit_hit'));
        }

        if ($this->missing > 0) {
            $this->info($this->trans('optimize.missing_hint'));
        }
    }
}
