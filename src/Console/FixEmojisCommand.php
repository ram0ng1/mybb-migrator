<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\Support\TapatalkEmoji;
use Symfony\Component\Console\Input\InputOption;

/**
 * Substitui os placeholders `[emojiN]` do Tapatalk por caracteres Unicode em
 * posts.content e discussions.title já gravados. Não toca em linhas sem o
 * padrão (o LIKE filtra antes do scan).
 */
class FixEmojisCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:fix-emojis')
            ->setDescription('Converte [emojiN] do Tapatalk para Unicode em posts e títulos de discussões já migrados.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirma execução.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Rode com --force.');
            return 1;
        }

        $postsFixed = $this->fixTable('posts', 'content');
        $discussionsFixed = $this->fixTable('discussions', 'title');

        $this->info('Concluído.');
        $this->info("  posts ajustados        : {$postsFixed}");
        $this->info("  discussões ajustadas   : {$discussionsFixed}");

        return 0;
    }

    private function fixTable(string $table, string $column): int
    {
        $fixed = 0;

        $this->db->table($table)
            ->where($column, 'LIKE', '%[emoji%')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $column, &$fixed) {
                foreach ($rows as $row) {
                    $old = (string) $row->{$column};
                    $new = TapatalkEmoji::convert($old);

                    if ($new !== $old) {
                        $this->db->table($table)->where('id', $row->id)->update([$column => $new]);
                        $fixed++;
                    }
                }
                $this->info("  {$table}: {$fixed} ajustados...");
            });

        return $fixed;
    }
}
