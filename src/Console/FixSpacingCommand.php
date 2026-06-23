<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Formatter\Formatter;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\BBCode\Converter;
use Symfony\Component\Console\Input\InputOption;

/**
 * Corrige a FIDELIDADE DE ESPAÇAMENTO de posts JÁ MIGRADOS, re-derivando-os da
 * origem MyBB com o Converter já corrigido.
 *
 * O problema: o MyBB renderiza com `nl2br` — CADA quebra de linha vira um `<br>`
 * e linhas em branco consecutivas NUNCA colapsam. O litedown (flarum/markdown),
 * ao contrário, junta qualquer número de linhas em branco numa única quebra de
 * parágrafo. Resultado: posts migrados perderam vãos verticais (2/3 linhas em
 * branco viraram uma só) e linhas em branco viraram parágrafos em vez de `<br>`.
 *
 * O Converter agora preenche cada linha em branco com um marcador invisível
 * (U+200B) para reproduzir o `nl2br`. Como a informação de espaçamento perdida
 * NÃO existe mais no XML armazenado (foi colapsada na migração), a única forma
 * fiel de consertar é reler a mensagem original e reconvertê-la.
 *
 * Escopo: posts de fórum (is_private=0) cuja mensagem de origem contém uma linha
 * em branco (duas quebras seguidas, em qualquer forma: `\n\n`, `\r\n\r\n`,
 * `\r\r\n\r\r\n`). Só grava quando o XML reconvertido difere do atual.
 *
 * Idempotente: reler a mesma origem produz o mesmo XML. Como re-parseia o post a
 * partir da origem, os passes de Fase 3 que dependem de pós-processamento
 * (fix-smilies, fix-user-mentions, fix-mention-slugs, estilo de quotes,
 * revert-md-strike-sub, revert-ispoiler) precisam ser RE-EXECUTADOS DEPOIS, nos
 * posts reconstruídos (são idempotentes e só alteram o que casar). Veja README §4.
 */
class FixSpacingCommand extends AbstractCommand
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
            ->setName('mybb:fix-spacing')
            ->setDescription('Re-derives already-migrated posts from the MyBB source to restore faithful nl2br spacing (no collapsed blank lines).')
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
            $this->info('Scanning MyBB source for posts with blank lines (spacing the migration collapsed)…');
            // Uma "linha em branco" é qualquer par de quebras consecutivas (com no
            // máximo espaços/tabs entre elas) — em qualquer forma de newline. É um
            // SUPERCONJUNTO seguro dos posts afetados: posts sem linha em branco
            // não mudam, e o gate de diff abaixo evita gravações desnecessárias.
            $srcRows = $mybb->select(
                "SELECT pid FROM {$prefix}posts WHERE message REGEXP ?",
                ["(\r\n|\r|\n)[ \t]*(\r\n|\r|\n)"]
            );
            while ($r = $srcRows->fetch()) {
                $pids[(int) $r['pid']] = true;
            }
        }

        $affected = array_keys($pids);
        $this->info(($dryRun ? '[dry-run] ' : '') . 'Candidate posts: ' . count($affected));

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
            $this->output->writeln('<comment>Posts were re-parsed from source. Re-run the idempotent Phase-3 content</comment>');
            $this->output->writeln('<comment>passes you use (fix-smilies, fix-user-mentions, fix-mention-slugs, your</comment>');
            $this->output->writeln('<comment>quote-style pass, revert-md-strike-sub, revert-ispoiler) to restore them.</comment>');
        }

        return 0;
    }

    /**
     * Texto plano (`<t>`) usado quando o formatter falha — garante XML válido
     * sem perder o conteúdo. Igual ao fallback de mybb:content / rebuild-formatting.
     */
    private function plainTextFallback(string $text): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';

        return '<t>' . htmlspecialchars($clean, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</t>';
    }
}
