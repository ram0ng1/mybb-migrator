<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Formatter\Formatter;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\BBCode\Converter;
use Symfony\Component\Console\Input\InputOption;

/**
 * Re-parseia posts que contêm BBCode não suportado pelo s9e (size por nome,
 * [font=…], [align=center], [hr], [php], [indent]) — aplica o
 * `Converter::normalizeBbcode()` em cima do XML existente, unparse → re-parse
 * para que cor/bold/itálico/etc fiquem renderizáveis.
 *
 * O critério de elegibilidade procura por sintomas no XML: literais
 * `[size=algo]`, `[font=…]`, etc, dentro do conteúdo já serializado. O fix
 * de quote/POSTMENTION/UserMention NÃO é tocado.
 */
class NormalizeBbcodeCommand extends AbstractCommand
{
    public function __construct(
        protected ConnectionInterface $db,
        protected Formatter $formatter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:normalize-bbcode')
            ->setDescription('Re-parseia posts.content aplicando Converter::normalizeBbcode (size/font/align/hr/php).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirma execução.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Rode com --force.');
            return 1;
        }

        // Sintomas: literais como [size=NAME] (não numérico), [font=, [align=
        // (não-center), [hr], [php]. Vamos pegar superset com LIKE %[size=%
        // OR %[font=% OR %[align=% OR %[hr%] OR %[php% e refazer.
        $this->info('Buscando posts com BBCode não normalizado…');

        $base = $this->db->table('posts')
            ->where(function ($q) {
                $q->where('content', 'LIKE', '%[size=%')
                  ->orWhere('content', 'LIKE', '%[font=%')
                  ->orWhere('content', 'LIKE', '%[align=%')
                  ->orWhere('content', 'LIKE', '%[hr%')
                  ->orWhere('content', 'LIKE', '%[/font]%')
                  ->orWhere('content', 'LIKE', '%[/align]%')
                  ->orWhere('content', 'LIKE', '%[php]%')
                  ->orWhere('content', 'LIKE', '%[indent]%');
            });

        $total = (int) (clone $base)->count();
        $this->info("Posts elegíveis: {$total}");

        $done = 0;
        $skipped = 0;
        $failed = 0;

        $base->select('id', 'content')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$done, &$skipped, &$failed) {
                foreach ($rows as $row) {
                    $xml = (string) $row->content;

                    if (! str_starts_with(ltrim($xml), '<r>') && ! str_starts_with(ltrim($xml), '<t>')) {
                        $skipped++;
                        continue;
                    }

                    try {
                        $raw = $this->formatter->unparse($xml);
                        $normalized = Converter::normalizeBbcode($raw);
                        if ($normalized === $raw) {
                            $skipped++;
                            continue;
                        }
                        $newXml = $this->formatter->parse($normalized);
                    } catch (\Throwable $e) {
                        $failed++;
                        continue;
                    }

                    if ($newXml === $xml) {
                        $skipped++;
                        continue;
                    }

                    $this->db->table('posts')->where('id', $row->id)->update(['content' => $newXml]);
                    $done++;
                }
                $this->info("  {$done} re-parseados, {$skipped} ignorados, {$failed} falharam");
            }, 'id', 'id');

        $this->info('Concluído.');
        $this->info("  re-parseados : {$done}");
        $this->info("  ignorados    : {$skipped}");
        $this->info("  falharam     : {$failed}");

        return 0;
    }
}
