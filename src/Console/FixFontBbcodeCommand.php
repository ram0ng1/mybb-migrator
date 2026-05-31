<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Remove os marcadores `[font=...]` e `[/font]` literais de posts.content e
 * discussions.title sem mexer no conteúdo de texto interno.
 *
 * O flarum/bbcode não tem o tag FONT por padrão, então essas marcas ficavam
 * visíveis como texto literal na renderização. Como a escolha de fonte do
 * MyBB raramente é importante para fidelidade do conteúdo, removemos só os
 * marcadores e mantemos o texto que estava dentro.
 */
class FixFontBbcodeCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:fix-font-bbcode')
            ->setDescription('Remove os marcadores [font=...] e [/font] literais sem alterar o texto interno.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirma execução.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Rode com --force.');
            return 1;
        }

        $totalPosts = 0;
        $totalTitles = 0;

        $this->info('Reparando posts.content...');
        $this->db->table('posts')
            ->where('content', 'LIKE', '%[font=%')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$totalPosts) {
                foreach ($rows as $row) {
                    $old = (string) $row->content;
                    $new = self::strip($old);

                    if ($new !== $old) {
                        $this->db->table('posts')->where('id', $row->id)->update(['content' => $new]);
                        $totalPosts++;
                    }
                }
                $this->info("  posts ajustados: {$totalPosts}");
            });

        $this->info('Reparando discussions.title...');
        $this->db->table('discussions')
            ->where('title', 'LIKE', '%[font=%')
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$totalTitles) {
                foreach ($rows as $row) {
                    $old = (string) $row->title;
                    $new = self::strip($old);

                    if ($new !== $old) {
                        $this->db->table('discussions')->where('id', $row->id)->update(['title' => $new]);
                        $totalTitles++;
                    }
                }
                $this->info("  títulos ajustados: {$totalTitles}");
            });

        $this->info('Concluído.');
        $this->info("  posts ajustados      : {$totalPosts}");
        $this->info("  discussões ajustadas : {$totalTitles}");

        return 0;
    }

    /**
     * Remove `[font=qualquer-coisa]` e `[/font]` (case-insensitive), preservando
     * o conteúdo entre eles. Aplica recursivamente caso tenha aninhamento
     * raso de marcadores.
     */
    public static function strip(string $text): string
    {
        return (string) preg_replace('#\[/?font(?:=[^\]]*)?\]#i', '', $text);
    }
}
