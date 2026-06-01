<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\Support\Charset;
use Symfony\Component\Console\Input\InputOption;

/**
 * Aplica Charset::fix em posts.content e discussions.title já gravados, para
 * reparar mojibake (UTF-8 lido como Windows-1252 e re-codificado) que tenha
 * passado pela migração quando o Converter ainda estava com heurística antiga.
 *
 * O Charset::fix tem um round-trip embutido: textos limpos passam intactos,
 * só mojibake real é convertido.
 */
class FixCharsetCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:fix-charset')
            ->setDescription('Repairs mojibake in already-migrated posts.content and discussions.title.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Run with --force.');
            return 1;
        }

        $this->info('Repairing posts...');
        $postsFixed = $this->fixTable('posts', 'content');

        $this->info('Repairing discussion titles...');
        $discussionsFixed = $this->fixTable('discussions', 'title');

        $this->info('Done.');
        $this->info("  posts fixed        : {$postsFixed}");
        $this->info("  discussions fixed  : {$discussionsFixed}");

        return 0;
    }

    private function fixTable(string $table, string $column): int
    {
        $fixed = 0;
        $seen = 0;

        $this->db->table($table)
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use ($table, $column, &$fixed, &$seen) {
                foreach ($rows as $row) {
                    $old = (string) $row->{$column};
                    $new = Charset::fix($old);
                    $seen++;

                    if ($new !== $old) {
                        $this->db->table($table)->where('id', $row->id)->update([$column => $new]);
                        $fixed++;
                    }
                }
                $this->info("  {$table}: {$seen} scanned, {$fixed} fixed");
            });

        return $fixed;
    }
}
