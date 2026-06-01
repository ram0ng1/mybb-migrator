<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Converte as citações migradas do estilo "caixa cheia" (POSTMENTION + QUOTE com
 * todo o texto citado) para o estilo NATIVO COMPACTO do Flarum (somente a
 * POSTMENTION `@"Autor"#pPID`, que renderiza a barrinha "↩ autor [trecho]"
 * puxando o post real).
 *
 * Mantém a caixa cheia quando o post citado foi deletado (POSTMENTION
 * deleted="1") — aí não há post para puxar o trecho e remover perderia o texto.
 *
 * Roda só sobre o XML do s9e: remove o `<QUOTE>…</QUOTE>` que segue uma
 * POSTMENTION viva, com varredura de profundidade (suporta quotes aninhados) e
 * revalidação do XML resultante (posts que não validam são pulados).
 */
class CompactQuotesCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    public function __construct(
        protected ConnectionInterface $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:compact-quotes')
            ->setDescription('Converts migrated QUOTEs to the compact style (POSTMENTION only).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually apply changes (without this it is a dry-run).')
            ->addOption('pid', null, InputOption::VALUE_REQUIRED, 'Test on a specific post (preview, does not save).');
    }

    protected function fire(): int
    {
        $pid = $this->input->getOption('pid');

        if ($pid !== null) {
            return $this->preview((int) $pid);
        }

        $force = (bool) $this->input->getOption('force');
        $query = $this->db->table('posts')
            ->where('type', 'comment')
            ->where('content', 'LIKE', '%<QUOTE author=%')
            ->where('content', 'LIKE', '%<POSTMENTION%')
            ->select('id', 'content');

        $changed = 0; $skipped = 0; $scanned = 0;

        foreach ($query->cursor() as $row) {
            $scanned++;
            $new = $this->compact((string) $row->content);

            if ($new === null || $new === $row->content) {
                continue;
            }

            // Revalida: tem que continuar sendo XML s9e válido.
            if (! $this->validXml($new)) {
                $skipped++;
                continue;
            }

            if ($force) {
                $this->db->table('posts')->where('id', $row->id)->update(['content' => $new]);
            }
            $changed++;

            if ($changed % 2000 === 0) {
                $this->info("  {$changed} changed...");
            }
        }

        $mode = $force ? 'APPLIED' : 'DRY-RUN (use --force)';
        $this->info("[{$mode}] scanned={$scanned}, to change={$changed}, skipped(invalid XML)={$skipped}");

        return 0;
    }

    private function preview(int $pid): int
    {
        $row = $this->db->table('posts')->where('id', $pid)->first(['content']);
        if (! $row) {
            $this->error("Post {$pid} not found.");
            return 1;
        }
        $new = $this->compact((string) $row->content);
        $this->info("--- BEFORE ---\n" . $row->content);
        $this->info("--- AFTER ---\n" . ($new ?? '(no change)'));
        $this->info('Valid XML: ' . (($new !== null && $this->validXml($new)) ? 'yes' : 'no'));
        return 0;
    }

    /**
     * Remove cada `<QUOTE>…</QUOTE>` que segue imediatamente uma POSTMENTION viva
     * (sem deleted="1"). Devolve null se nada mudou.
     */
    private function compact(string $xml): ?string
    {
        $changed = false;
        $offset = 0;

        while (($pmEnd = strpos($xml, '</POSTMENTION>', $offset)) !== false) {
            $pmStart = strrpos(substr($xml, 0, $pmEnd), '<POSTMENTION');
            $afterPm = $pmEnd + strlen('</POSTMENTION>');

            // POSTMENTION viva? (não deleted="1")
            $pmTag = substr($xml, $pmStart, $pmEnd - $pmStart);
            $alive = ! str_contains($pmTag, 'deleted="1"');

            if ($alive && substr($xml, $afterPm, 6) === '<QUOTE') {
                $qEnd = $this->matchQuoteEnd($xml, $afterPm);
                if ($qEnd !== null) {
                    $xml = substr($xml, 0, $afterPm) . substr($xml, $qEnd);
                    $changed = true;
                    $offset = $afterPm;
                    continue;
                }
            }

            $offset = $afterPm;
        }

        return $changed ? $xml : null;
    }

    /**
     * Dado o índice de um `<QUOTE`, devolve o índice logo após o `</QUOTE>` que
     * o fecha (respeitando aninhamento), ou null se desbalanceado.
     */
    private function matchQuoteEnd(string $xml, int $start): ?int
    {
        $depth = 0;
        $i = $start;
        $len = strlen($xml);

        while ($i < $len) {
            $open = strpos($xml, '<QUOTE', $i);
            $close = strpos($xml, '</QUOTE>', $i);

            if ($close === false) {
                return null;
            }

            if ($open !== false && $open < $close) {
                $depth++;
                $i = $open + 6;
            } else {
                $depth--;
                $i = $close + strlen('</QUOTE>');
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function validXml(string $xml): bool
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml);
            return $doc !== false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }
}
