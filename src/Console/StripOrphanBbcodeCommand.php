<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Remove marcadores BBCode literais órfãos (`[b]`, `[/color]`, `[font=...]`,
 * `[/size]` etc.) que sobraram em `posts.content` por aninhamento mal-formado
 * do MyBB original — o s9e/TextFormatter parseou o par válido em XML e
 * deixou o "sobre" como texto.
 *
 * Preserva o XML já parseado (`<B>`, `<COLOR>`, `<SIZE>`, etc.) e o conteúdo
 * de elementos `<CODE>` e `<s>...</s>` (fonte original mantida pelo s9e).
 */
class StripOrphanBbcodeCommand extends AbstractCommand
{
    private const TAG_LIST = 'b|i|u|s|strike|del|ins|color|font|size|align|center|left|right|justify|hr|indent|mention|sub|sup';

    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:strip-orphan-bbcode')
            ->setDescription('Remove orphan literal BBCode markers (`[b]`, `[/color]`, etc.) that were left unparsed.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Run with --force.');
            return 1;
        }

        $totalPosts = 0;
        $totalTitles = 0;

        $this->info('Repairing posts.content...');
        $this->db->table('posts')
            ->where('content', 'LIKE', '%[%]%')
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
                $this->info("  posts fixed: {$totalPosts}");
            });

        $this->info('Repairing discussions.title...');
        $this->db->table('discussions')
            ->where('title', 'LIKE', '%[%]%')
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
                $this->info("  titles fixed: {$totalTitles}");
            });

        $this->info('Done.');
        $this->info("  posts fixed          : {$totalPosts}");
        $this->info("  discussions fixed    : {$totalTitles}");

        return 0;
    }

    /**
     * Estratégia: preserva o conteúdo de `<s>...</s>` (fonte original do s9e),
     * `<CODE>...</CODE>` e `<URL>...</URL>` (que podem ter `[bbcode]` legítimo),
     * faz strip nas demais regiões usando um delimitador placeholder, e
     * recompõe.
     */
    public static function strip(string $xml): string
    {
        $protected = [];
        $placeholderTpl = "\x00PROTECTED_%d\x00";

        $protect = static function (string $s) use (&$protected, $placeholderTpl): string {
            $key = sprintf($placeholderTpl, count($protected));
            $protected[] = $s;
            return $key;
        };

        $xml = preg_replace_callback('#<s>.*?</s>#s', static fn (array $m): string => $protect($m[0]), $xml) ?? $xml;
        $xml = preg_replace_callback('#<e>.*?</e>#s', static fn (array $m): string => $protect($m[0]), $xml) ?? $xml;
        $xml = preg_replace_callback('#<CODE\b.*?</CODE>#s', static fn (array $m): string => $protect($m[0]), $xml) ?? $xml;
        $xml = preg_replace_callback('#<URL\b.*?</URL>#s', static fn (array $m): string => $protect($m[0]), $xml) ?? $xml;

        $xml = (string) preg_replace('#\[/?(?:' . self::TAG_LIST . ')\b[^\]]*\]#i', '', $xml);

        // url/img: só os marcadores SEM dado — `[/url]`, `[img]`, `[/img]` órfãos
        // (sobra de aninhamento malformado do MyBB, ex.: `…[/IMG][/URL][/img]`).
        // `[url=...]` de ABERTURA NÃO é removido (carrega a URL); o conteúdo
        // legítimo já está protegido em <URL>/<s>/<e>.
        $xml = (string) preg_replace('#\[/url\]#i', '', $xml);
        $xml = (string) preg_replace('#\[/?img\]#i', '', $xml);

        foreach ($protected as $i => $original) {
            $xml = str_replace(sprintf($placeholderTpl, $i), $original, $xml);
        }

        return $xml;
    }
}
