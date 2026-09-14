<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\Support\XmlText;
use Symfony\Component\Console\Input\InputOption;

/**
 * Remove de `posts.content` (e de `discussions.title`) os caracteres que o XML
 * não aceita — na prática o byte NUL deixado pelos placeholders do
 * `mybb:strip-orphan-bbcode` — reconstruindo a fonte `<s>`/`<e>` de `<URL>` e
 * `<CODE>` onde ela foi engolida. Ver `XmlText`.
 *
 * Sintoma que este comando cura: `InvalidArgumentException: Cannot load XML:
 * PCDATA invalid Char value 0` no log, e páginas (índice de tags, listas) que
 * ficam vazias porque a API inteira falha ao serializar um único post.
 */
class FixControlCharsCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:fix-control-chars')
            ->setDescription('Strips XML-invalid control characters (NUL etc.) from posts.content and discussions.title.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the affected rows; write nothing.');
    }

    protected function fire(): int
    {
        $dryRun = (bool) $this->input->getOption('dry-run');

        if (! $dryRun && ! $this->input->getOption('force')) {
            $this->error('Run with --force (or --dry-run to only list affected rows).');
            return 1;
        }

        $this->info($dryRun ? 'Scanning posts.content (dry run)...' : 'Repairing posts.content...');
        $posts = $this->fixTable('posts', 'content', $dryRun);

        $this->info($dryRun ? 'Scanning discussions.title (dry run)...' : 'Repairing discussions.title...');
        $titles = $this->fixTable('discussions', 'title', $dryRun);

        $this->info('Done.');
        $this->info('  posts ' . ($dryRun ? 'affected' : 'fixed') . "       : {$posts}");
        $this->info('  discussions ' . ($dryRun ? 'affected' : 'fixed') . " : {$titles}");

        return 0;
    }

    private function fixTable(string $table, string $column, bool $dryRun): int
    {
        $fixed = 0;
        $seen = 0;

        $this->db->table($table)
            ->select(['id', $column])
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use ($table, $column, $dryRun, &$fixed, &$seen) {
                foreach ($rows as $row) {
                    $seen++;
                    $old = (string) $row->{$column};

                    if (! XmlText::isDirty($old)) {
                        continue;
                    }

                    $fixed++;

                    if ($dryRun) {
                        $this->info("  {$table}#{$row->id}");
                        continue;
                    }

                    $this->db->table($table)->where('id', $row->id)->update([$column => XmlText::clean($old)]);
                }
            });

        $this->info("  {$table}: {$seen} scanned, {$fixed} " . ($dryRun ? 'affected' : 'fixed'));

        return $fixed;
    }
}
