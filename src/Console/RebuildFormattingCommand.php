<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Formatter\Formatter;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\BBCode\Converter;
use Symfony\Component\Console\Input\InputOption;

/**
 * Re-deriva (a partir do MyBB de origem) posts cujo BBCode inline foi quebrado
 * pelo markdown — tags de fechamento órfãs (`[/b][/size][/color]` como texto),
 * cor/tamanho perdidos em listas, e o artefato `\r\r\n` (CR duplo) que dobrava o
 * espaçamento. A informação perdida (cores, layout) NÃO existe mais no XML
 * armazenado, então a única forma fiel de consertar é reler a mensagem original
 * e reconvertê-la com o Converter já corrigido (colapso de `\r\r\n`, escape de
 * pseudo-listas, redistribuição de formatação inline).
 *
 * Escopo (posts forum, is_private=0):
 *   1. conteúdo com tags de fechamento órfãs consecutivas (`…][/…`) ou `[/font]`
 *      / `[/align]` literais — sinal forte da quebra inline-sobre-blocos;
 *   2. posts cuja mensagem de origem contém o artefato `\r\r\n`.
 *
 * Idempotente: reler a mesma origem produz o mesmo XML. Como re-parseia o post,
 * os passes de Fase 3 que dependem de pós-processamento (fix-smilies,
 * fix-user-mentions, fix-mention-slugs, estilo de quotes, revert-md-strike-sub,
 * revert-ispoiler) precisam ser re-executados DEPOIS, nos posts reconstruídos
 * (são idempotentes e só alteram o que casar). Veja o README §4.
 */
class RebuildFormattingCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    private const CHUNK = 500;

    private int $parseFailures = 0;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected Formatter $formatter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:rebuild-formatting')
            ->setDescription('Re-derives posts with broken inline BBCode / doubled spacing from the MyBB source, faithful to the original.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution (writes to DB).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report how many posts would change without writing.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Process a single post id (for inspection).');
        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        $dryRun = (bool) $this->input->getOption('dry-run');

        if (! $dryRun && ! $this->input->getOption('force')) {
            $this->error('Run with --force (or --dry-run to preview).');
            return 1;
        }

        $mybb = $this->buildMybbDatabase($this->settings);
        $prefix = $mybb->prefix();

        $pids = [];

        if ($single = $this->input->getOption('id')) {
            $pids[(int) $single] = true;
        } else {
            $this->info('Scanning Flarum content for markdown artifacts (orphan tags, code, headings)…');
            $this->db->table('posts')
                ->where('is_private', 0)
                ->where(function ($q) {
                    $q->where('content', 'LIKE', '%][/%')           // tags de fechamento consecutivas (órfãs)
                      ->orWhere('content', 'LIKE', '%[/font]%')      // font órfão (Converter sempre tira [font])
                      ->orWhere('content', 'LIKE', '%[/align]%')     // align órfão
                      ->orWhere('content', 'LIKE', '%</i><CODE>%')   // bloco de código por indentação (markdown)
                      ->orWhere('content', 'LIKE', '%<H1>%')         // heading markdown (# / setext)
                      ->orWhere('content', 'LIKE', '%<H2>%')
                      ->orWhere('content', 'LIKE', '%<H3>%')
                      ->orWhere('content', 'LIKE', '%<H4>%')
                      ->orWhere('content', 'LIKE', '%<H5>%')
                      ->orWhere('content', 'LIKE', '%<H6>%');
                })
                ->orderBy('id')
                ->pluck('id')
                ->each(function ($id) use (&$pids) {
                    $pids[(int) $id] = true;
                });

            $this->info('Scanning MyBB source for markdown-trigger artifacts (spacing/code/heading/quote)…');
            // Constructos que o litedown interpreta mas o MyBB não tem:
            //  - \r\r\n  → espaçamento dobrado;
            //  - TAB / 4 espaços no início da linha → bloco de código;
            //  - `#`/`>` no início da linha → heading / blockquote.
            // Todos consertados relendo do source com o Converter corrigido.
            // CHAR(13)=CR, CHAR(10)=LF, CHAR(9)=TAB. (Sem comentários inline na
            // SQL: escapes \r\n\t numa string PHP "..." viram bytes reais.)
            $srcRows = $mybb->select(
                "SELECT pid FROM {$prefix}posts WHERE "
                . "message LIKE CONCAT('%', CHAR(13), CHAR(13), CHAR(10), '%') "  // \r\r\n
                . "OR message LIKE CONCAT('%', CHAR(10), CHAR(9), '%') "          // LF+TAB
                . "OR message LIKE CONCAT('%', CHAR(13), CHAR(9), '%') "          // CR+TAB
                . "OR message LIKE CONCAT(CHAR(9), '%') "                         // leading TAB
                . "OR message LIKE CONCAT('%', CHAR(10), '    ', '%') "           // LF + 4 spaces
                . "OR message LIKE CONCAT('%', CHAR(13), '    ', '%') "           // CR + 4 spaces
                . "OR message LIKE CONCAT('%', CHAR(10), '#', '%') "             // LF + # heading
                . "OR message LIKE CONCAT('%', CHAR(13), '#', '%') "             // CR + # heading
                . "OR message LIKE CONCAT('#', '%') "                            // leading #
                . "OR message LIKE CONCAT('%', CHAR(10), '>', '%') "             // LF + > quote
                . "OR message LIKE CONCAT('%', CHAR(13), '>', '%') "             // CR + > quote
                . "OR (message LIKE '%www.%' "                                  // bare www. URL
                .     "AND message NOT LIKE '%]www.%' "                         //  (não dentro de [url]…)
                .     "AND message NOT LIKE '%/www.%' "                         //  (não em http://www…)
                .     "AND message NOT LIKE '%=www.%')"                         //  (não em url=www…)
            );
            while ($r = $srcRows->fetch()) {
                $pids[(int) $r['pid']] = true;
            }
        }

        $affected = array_keys($pids);
        $this->info(($dryRun ? '[dry-run] ' : '') . 'Affected posts: ' . count($affected));

        $changed = 0;
        $unchanged = 0;
        $skipped = 0;   // privado / não-forum / sem post no Flarum
        $missing = 0;   // sem origem no MyBB
        $this->parseFailures = 0;

        foreach (array_chunk($affected, self::CHUNK) as $chunk) {
            /** @var array<int, string> $existing  [id => content] */
            $existing = $this->db->table('posts')
                ->whereIn('id', $chunk)
                ->where('is_private', 0)
                ->pluck('content', 'id')
                ->all();

            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $srcStmt = $mybb->select(
                "SELECT pid, message FROM {$prefix}posts WHERE pid IN ({$placeholders})",
                array_values($chunk)
            );
            $src = [];
            while ($r = $srcStmt->fetch()) {
                $src[(int) $r['pid']] = (string) $r['message'];
            }

            foreach ($chunk as $pid) {
                if (! array_key_exists($pid, $existing)) {
                    $skipped++;
                    continue;
                }
                if (! array_key_exists($pid, $src)) {
                    $missing++;
                    continue;
                }

                $converted = Converter::convert($src[$pid]);
                try {
                    $xml = $this->formatter->parse($converted);
                } catch (\Throwable $e) {
                    $this->parseFailures++;
                    $xml = $this->plainTextFallback($converted);
                }

                if ($xml === (string) $existing[$pid]) {
                    $unchanged++;
                    continue;
                }

                if (! $dryRun) {
                    $this->db->table('posts')->where('id', $pid)->update(['content' => $xml]);
                }
                $changed++;
            }

            $this->info("  processed … changed {$changed} / unchanged {$unchanged}");
        }

        $this->info('Done.');
        $this->info('  ' . ($dryRun ? 'would change' : 'changed') . "        : {$changed}");
        $this->info("  unchanged                : {$unchanged}");
        $this->info("  skipped (private/no-post): {$skipped}");
        $this->info("  missing in MyBB source   : {$missing}");
        if ($this->parseFailures > 0) {
            $this->error("  parse failures (kept as plain text): {$this->parseFailures}");
        }

        if (! $dryRun && $changed > 0) {
            $this->warn('Rebuilt posts were re-parsed from source. Re-run the idempotent Phase-3');
            $this->warn('content passes you use (fix-smilies, fix-user-mentions, fix-mention-slugs,');
            $this->warn('your quote-style pass, revert-md-strike-sub, revert-ispoiler) to restore them.');
        }

        return 0;
    }

    /**
     * Texto plano (`<t>`) usado quando o formatter falha — garante XML válido
     * sem perder o conteúdo. Igual ao fallback de mybb:content.
     */
    private function plainTextFallback(string $text): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';

        return '<t>' . htmlspecialchars($clean, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</t>';
    }
}
