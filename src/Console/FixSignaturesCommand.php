<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\Support\Charset;
use Symfony\Component\Console\Input\InputOption;

/**
 * Limpa `users.bio` (assinaturas migradas do MyBB) para que apareçam de
 * forma legível na extensão damonhu/flarum-ext-biosignature, que renderiza
 * a bio como texto puro no rodapé de cada post.
 *
 * Como o display é Mithril plain text (sem BBCode/HTML), removemos os tags
 * BBCode de formatação preservando o conteúdo interno, normalizamos `\n`
 * literais em espaços, e fazemos trim.
 */
class FixSignaturesCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:fix-signatures')
            ->setDescription('Limpa users.bio (assinaturas) — strip BBCode literal, normaliza newlines.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirma execução.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Rode com --force.');
            return 1;
        }

        $total = 0;

        $this->db->table('users')
            ->whereNotNull('bio')
            ->where('bio', '<>', '')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$total) {
                foreach ($rows as $row) {
                    $old = (string) $row->bio;
                    $new = self::clean($old);

                    if ($new !== $old) {
                        $this->db->table('users')->where('id', $row->id)->update(['bio' => $new === '' ? null : $new]);
                        $total++;
                    }
                }
                $this->info("  bios ajustadas: {$total}");
            });

        $this->info('Concluído.');
        $this->info("  bios ajustadas : {$total}");

        return 0;
    }

    /**
     * Limpa uma assinatura MyBB para exibição plain-text na biosignature:
     *  - Remove tags BBCode mais comuns preservando o texto interno
     *    (`[color=red]X[/color]` -> `X`).
     *  - Remove `[img]url[/img]`, `[url=...]label[/url]` (mantém label),
     *    `[email=...]label[/email]` (mantém label), `[mention=...]X[/mention]`.
     *  - Substitui `\n` literais e CR/LF reais por espaço.
     *  - Colapsa espaços em branco múltiplos e faz trim.
     */
    public static function clean(string $bio): string
    {
        $bio = Charset::fix($bio);

        $bio = (string) preg_replace_callback(
            '#\[url=[^\]]+\]([^\[]*)\[/url\]#i',
            static fn (array $m): string => $m[1],
            $bio
        );

        $bio = (string) preg_replace_callback(
            '#\[email=[^\]]+\]([^\[]*)\[/email\]#i',
            static fn (array $m): string => $m[1],
            $bio
        );

        $bio = (string) preg_replace_callback(
            '#\[mention=[^\]]*\]([^\[]*)\[/mention\]#i',
            static fn (array $m): string => '@' . $m[1],
            $bio
        );

        $bio = (string) preg_replace('#\[img\][^\[]*\[/img\]#i', '', $bio);
        $bio = (string) preg_replace('#\[/?(?:b|i|u|s|strike|del|color|font|size|align|center|left|right|justify|sub|sup|hr|indent|quote|code|list|\*|email|url|img|video|attachment|mention|youtube|table|tr|td|th)\b[^\]]*\]#i', '', $bio);
        $bio = str_replace(['\\r\\n', '\\n', '\\r', "\r\n", "\r", "\n"], ' ', $bio);
        $bio = (string) preg_replace('/\s+/', ' ', $bio);

        return trim($bio);
    }
}
