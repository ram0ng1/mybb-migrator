<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Remove o ID redundante no FINAL do slug das discussões.
 *
 * A migração deixou slugs como `buying-selling-safely-33970`, e como o Flarum
 * monta a URL no formato `/d/{id}-{slug}`, o ID acaba aparecendo duas vezes:
 *
 *   /d/33970-buying-selling-safely-33970
 *            └ id ┘                └ id ┘ (sufixo redundante)
 *
 * Este comando corta o sufixo `-{id}` quando ele bate com o próprio id da
 * discussão, resultando em `buying-selling-safely` → `/d/33970-buying-selling-safely`.
 *
 * Só remove quando o número final é exatamente o id da discussão — slugs que
 * legitimamente terminam em outro número (ex.: `windows-11`) ficam intactos.
 * Slugs do Flarum não precisam ser únicos (a URL sempre traz o id na frente),
 * então não há risco de colisão. Idempotente.
 */
class FixDiscussionSlugsCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:fix-discussion-slugs')
            ->setDescription('Removes the redundant trailing -{id} from discussion slugs.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force') && ! $this->input->getOption('dry-run')) {
            $this->error('Run with --force or --dry-run.');
            return 1;
        }

        $dryRun = (bool) $this->input->getOption('dry-run');

        $totalScanned = 0;
        $totalUpdated = 0;

        // Estreita o scan pra slugs que terminam em hífen + dígitos.
        $this->db->table('discussions')
            ->select('id', 'slug')
            ->whereRaw("slug REGEXP '-[0-9]+$'")
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$totalScanned, &$totalUpdated, $dryRun) {
                foreach ($rows as $row) {
                    $totalScanned++;
                    $new = $this->strip((string) $row->slug, (int) $row->id);

                    if ($new === null) {
                        continue;
                    }

                    if ($dryRun) {
                        fwrite(STDOUT, sprintf("  [%d] %s → %s\n", $row->id, $row->slug, $new));
                    } else {
                        $this->db->table('discussions')
                            ->where('id', $row->id)
                            ->update(['slug' => $new]);
                    }

                    $totalUpdated++;
                }
                $this->info("  scanned={$totalScanned} updated={$totalUpdated}");
            });

        if ($dryRun) {
            $this->info('(dry-run — end)');
        } else {
            $this->info('Done.');
        }

        $this->info("  slugs scanned : {$totalScanned}");
        $this->info("  slugs updated : {$totalUpdated}");

        return 0;
    }

    /**
     * Corta o sufixo `-{id}` do slug se ele bater com o id da discussão.
     * Retorna null quando não há nada a fazer (sufixo ausente ou resultado vazio).
     */
    public function strip(string $slug, int $id): ?string
    {
        $suffix = '-' . $id;

        if (! str_ends_with($slug, $suffix)) {
            return null;
        }

        $new = substr($slug, 0, -strlen($suffix));
        $new = rtrim($new, '-');

        // Slug que era só o id (ex.: "-33970" ou "33970") não tem o que reaproveitar.
        if ($new === '') {
            return null;
        }

        return $new === $slug ? null : $new;
    }
}
