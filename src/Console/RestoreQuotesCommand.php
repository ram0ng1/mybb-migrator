<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Formatter\Formatter;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\BBCode\Converter;
use Symfony\Component\Console\Input\InputOption;

/**
 * Restaura as CAIXAS de citação que o `mybb:compact-quotes` removeu.
 *
 * O compact-quotes apagou o `<QUOTE>…</QUOTE>` que seguia cada POSTMENTION viva,
 * deixando só o link (`↩ Autor`) e PERDENDO o texto citado. Como o texto original
 * continua na fonte MyBB, este comando RE-DERIVA o conteúdo desses posts a partir
 * de `dfsmybb_posts.message`, reproduzindo o pipeline da migração
 * (Converter → Formatter::parse). O resultado é, de novo, a menção `@"Autor"#pPID`
 * (link pro post citado) SEGUIDA da caixa `[quote="Autor"]…[/quote]` — fiel ao MyBB.
 *
 * ALVO: só posts COMPACTADOS — têm `<POSTMENTION>` e NÃO têm `<QUOTE>`, e cuja
 * fonte MyBB contém `[quote`. Posts que ainda têm a caixa ficam intactos.
 *
 * IMPORTANTE — re-derivar o post inteiro descarta os ajustes por-post de outros
 * passes (menções `@user` cruas promovidas, pseudo-listas, nicknames no conteúdo).
 * Por isso, DEPOIS deste comando rode de novo, nesta ordem:
 *     php flarum mybb:fix-user-mentions --force
 *     php flarum mybb:fix-pseudo-lists  --force
 *     php flarum mybb:apply-nicknames   --force   (se usou renomeações)
 *     php flarum cache:clear
 * Todos são idempotentes — posts já corretos não mudam.
 */
class RestoreQuotesCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    private const CHUNK = 300;

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
            ->setName('mybb:restore-quotes')
            ->setDescription('Restores full quote boxes (text + link) for posts compacted by mybb:compact-quotes, re-deriving from the MyBB source.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually apply changes (without this it is a dry-run count).')
            ->addOption('pid', null, InputOption::VALUE_REQUIRED, 'Preview a single post id (does not save).');
        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        $mybb = $this->buildMybbDatabase($this->settings);
        $prefix = $mybb->prefix();

        if ($pid = $this->input->getOption('pid')) {
            return $this->preview($mybb, $prefix, (int) $pid);
        }

        $force = (bool) $this->input->getOption('force');

        // Alvos a re-derivar (representações NÃO-canônicas de citação):
        //  (a) COMPACTADOS  — têm POSTMENTION e NÃO têm <QUOTE> (perderam a caixa);
        //  (b) MENÇÃO DUPLA — têm uma POSTMENTION com TEXTO INTERNO cru
        //      (`@"Autor"#pPID`, deixado por passes antigos) além da menção boa,
        //      o que renderiza DOIS chips "↩ Autor". Re-derivar da fonte produz
        //      uma única representação limpa (e o fix-quotes injeta UMA menção).
        $query = $this->db->table('posts')
            ->where('type', 'comment')
            ->where('content', 'LIKE', '%<POSTMENTION%')
            ->where(function ($q) {
                $q->where('content', 'NOT LIKE', '%<QUOTE%')
                  ->orWhere('content', 'LIKE', '%>@"%#p%</POSTMENTION>%');
            })
            ->select('id', 'content')
            ->orderBy('id');

        $scanned = 0; $restored = 0; $noSource = 0; $skipped = 0;
        $this->parseFailures = 0;

        $query->chunkById(self::CHUNK, function ($rows) use (&$scanned, &$restored, &$noSource, &$skipped, $mybb, $prefix, $force) {
            // Busca as mensagens-fonte do lote de uma vez.
            $pids = $rows->pluck('id')->all();
            $messages = $this->fetchMessages($mybb, $prefix, $pids);

            foreach ($rows as $row) {
                $scanned++;
                $pid = (int) $row->id;
                $message = $messages[$pid] ?? null;

                // Só restaura se a fonte realmente tinha citação.
                if ($message === null || stripos($message, '[quote') === false) {
                    $noSource++;
                    continue;
                }

                $new = $this->reconvert($message, $pid);
                if ($new === null || $new === (string) $row->content) {
                    $skipped++;
                    continue;
                }

                if ($force) {
                    $this->db->table('posts')->where('id', $pid)->update(['content' => $new]);
                }
                $restored++;
            }

            $this->info("  scanned {$scanned} / restored {$restored} / no-source {$noSource} / skipped {$skipped}");
        }, 'id', 'id');

        $mode = $force ? 'APPLIED' : 'DRY-RUN (use --force)';
        $this->info("[{$mode}] scanned={$scanned}, restored={$restored}, no-source-quote={$noSource}, skipped={$skipped}");
        if ($this->parseFailures > 0) {
            $this->error("  posts kept as plain text (formatter failed): {$this->parseFailures}");
        }
        if (! $force) {
            return 0;
        }

        $this->info('Reminder: now run mybb:fix-user-mentions, mybb:fix-pseudo-lists (and mybb:apply-nicknames), then cache:clear.');

        return 0;
    }

    /**
     * Re-deriva o XML do s9e a partir da mensagem-fonte do MyBB.
     */
    private function reconvert(string $message, int $pid): ?string
    {
        $normalized = Converter::convert($message);

        try {
            return $this->formatter->parse($normalized);
        } catch (\Throwable $e) {
            $this->parseFailures++;
            $this->error("  post pid={$pid}: formatter falhou ({$e->getMessage()}); mantido.");
            return null;
        }
    }

    /**
     * @param array<int, int> $pids
     * @return array<int, string>  pid => message
     */
    private function fetchMessages(\Ramon\MybbMigrator\MybbDatabase $mybb, string $prefix, array $pids): array
    {
        if ($pids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $pids));
        $out = [];
        foreach ($mybb->cursor("SELECT pid, message FROM {$prefix}posts WHERE pid IN ({$in})") as $r) {
            $out[(int) $r['pid']] = (string) $r['message'];
        }
        return $out;
    }

    private function preview(\Ramon\MybbMigrator\MybbDatabase $mybb, string $prefix, int $pid): int
    {
        $row = $this->db->table('posts')->where('id', $pid)->first(['content']);
        if (! $row) {
            $this->error("Post {$pid} not found in Flarum.");
            return 1;
        }
        $messages = $this->fetchMessages($mybb, $prefix, [$pid]);
        $message = $messages[$pid] ?? null;
        if ($message === null) {
            $this->error("Post {$pid} has no MyBB source.");
            return 1;
        }

        $new = $this->reconvert($message, $pid);
        $this->info("--- MyBB SOURCE ---\n" . $message);
        $this->info("\n--- CURRENT (compacted) ---\n" . $row->content);
        $this->info("\n--- RESTORED ---\n" . ($new ?? '(reconvert failed)'));

        if ($new !== null && $this->input->getOption('force')) {
            $this->db->table('posts')->where('id', $pid)->update(['content' => $new]);
            $this->info("\n[APPLIED to pid {$pid}]");
        }
        return 0;
    }
}
