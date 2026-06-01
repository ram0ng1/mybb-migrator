<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Converte `[size=X]...[/size]` literal (não parseado pelo s9e/TextFormatter
 * por estar aninhado em outras tags) em tags `<SIZE>` do flarum/bbcode estilo
 * mybb-to-flarum.
 *
 * O template do mybb-to-flarum espera valores choice (large/medium/small/etc.)
 * e usa `<SIZE choice="X">` com filterChain. Aqui geramos o XML que o renderer
 * já pode emitir (mesmo formato que o parser produziria).
 */
class FixSizeBbcodeCommand extends AbstractCommand
{
    private const VALID_CHOICES = ['xx-small', 'x-small', 'small', 'medium', 'large', 'x-large', 'xx-large'];

    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:fix-size-bbcode')
            ->setDescription('Converts literal [size=X]...[/size] into <SIZE> tags renderable by flarum/bbcode.')
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
            ->where('content', 'LIKE', '%[size=%')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$totalFixed) {
                foreach ($rows as $row) {
                    $old = (string) $row->content;
                    $new = self::fix($old);

                    if ($new !== $old) {
                        $this->db->table('posts')->where('id', $row->id)->update(['content' => $new]);
                        $totalFixed++;
                    }
                }
                $this->info("  {$totalFixed} posts fixed");
            });

        $this->info('Done.');
        $this->info("  posts fixed : {$totalFixed}");

        return 0;
    }

    /**
     * Converte `[size=X]TEXTO[/size]` para `<SIZE choice="X"><s>[size=X]</s>TEXTO<e>[/size]</e></SIZE>`.
     * Aceita X numérico (mapeado para o choice mais próximo) ou nome de choice válido.
     */
    public static function fix(string $xml): string
    {
        return (string) preg_replace_callback(
            '#\[size=([^\]]+)\]([^\[]*(?:\[(?!/?size)[^\]]*\][^\[]*)*)\[/size\]#i',
            static function (array $m): string {
                $raw = strtolower(trim((string) $m[1]));
                $body = (string) $m[2];

                $choice = self::mapToChoice($raw);

                if ($choice === null) {
                    return (string) $m[0];
                }

                return sprintf(
                    '<SIZE choice="%s"><s>[size=%s]</s>%s<e>[/size]</e></SIZE>',
                    $choice,
                    htmlspecialchars($raw, ENT_QUOTES | ENT_XML1, 'UTF-8'),
                    $body
                );
            },
            $xml
        );
    }

    private static function mapToChoice(string $raw): ?string
    {
        if (in_array($raw, self::VALID_CHOICES, true)) {
            return $raw;
        }

        if (is_numeric($raw)) {
            $n = (int) $raw;
            return match (true) {
                $n <= 8  => 'xx-small',
                $n <= 10 => 'x-small',
                $n <= 12 => 'small',
                $n <= 14 => 'medium',
                $n <= 18 => 'large',
                $n <= 24 => 'x-large',
                default  => 'xx-large',
            };
        }

        return null;
    }
}
