<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Remove os POSTMENTIONs que o FixQuotesCommand injetava ANTES da QUOTE.
 *
 * A motivação: o MyBB original já mostra o autor da citação dentro da caixa
 * do quote ("Author wrote"), então o POSTMENTION extra acima ficava visivelmente
 * duplicado. Apagar os POSTMENTIONs deixa só a caixa do quote, fiel ao MyBB.
 *
 * O índice `post_mentions_post` é preservado (não toca nele) — ele continua
 * habilitando o badge "respondeu a este post" no post original.
 */
class RevertQuoteMentionsCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:revert-quote-mentions')
            ->setDescription('Removes POSTMENTIONs injected before QUOTEs (leaves only the quote box, avoiding visual duplication).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Run with --force.');
            return 1;
        }

        $totalFixed = 0;

        $this->db->table('posts')
            ->where('content', 'LIKE', '%<POSTMENTION%')
            ->where('content', 'LIKE', '%<QUOTE%')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$totalFixed) {
                foreach ($rows as $row) {
                    $old = (string) $row->content;
                    $new = self::strip($old);

                    if ($new !== $old) {
                        $this->db->table('posts')->where('id', $row->id)->update(['content' => $new]);
                        $totalFixed++;
                    }
                }
                $this->info("  {$totalFixed} posts cleaned");
            });

        $this->info('Done.');
        $this->info("  posts cleaned : {$totalFixed}");

        return 0;
    }

    /**
     * Remove qualquer `<POSTMENTION ...>@X</POSTMENTION>` que esteja imediatamente
     * antes de `<QUOTE`. Esses só apareciam por injeção do FixQuotesCommand.
     */
    public static function strip(string $xml): string
    {
        return (string) preg_replace(
            '#<POSTMENTION\b[^>]*>.*?</POSTMENTION>(?=<QUOTE)#s',
            '',
            $xml
        );
    }
}
