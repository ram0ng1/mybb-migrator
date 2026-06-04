<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\BBCode\PseudoListFixer;
use Symfony\Component\Console\Input\InputOption;

/**
 * Conserta "pseudo-listas" nos posts migrados do MyBB.
 *
 * No MyBB o texto é renderizado literalmente (nl2br), então uma linha como
 * "1. item" ou "- item" aparece exatamente assim. Já o flarum/markdown
 * (litedown) interpreta esses inícios de linha como LISTAS markdown, gerando
 * `<LIST>`/`<LI>` — o que adiciona indentação, renumeração e espaçamento que
 * NÃO existiam no original (o sintoma de "espaçamento quebrado / nada fiel").
 *
 * Este passo reescreve apenas os blocos `<LIST>` derivados de markdown de volta
 * para texto literal (mantendo "1. ", "- ", "* " como texto visível), via
 * {@see PseudoListFixer} — uma cirurgia no XML do s9e/TextFormatter que NÃO
 * reprocessa o resto do post. Assim menções, quotes, bold, imagens, etc ficam
 * byte-a-byte intactos (um re-parse completo regrediria as menções já
 * corrigidas por FixUserMentions/FixMentionSlugs).
 *
 * Listas legítimas de BBCode `[list]`/`[*]` (que têm o marcador `<s>[list]</s>`)
 * são detectadas e preservadas sem alteração.
 */
class FixPseudoListsCommand extends AbstractCommand
{
    public function __construct(
        protected ConnectionInterface $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:fix-pseudo-lists')
            ->setDescription('Rewrites accidental markdown lists (1. / - / *) back to literal text, faithful to MyBB.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution (writes to DB).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report how many posts would change without writing.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Process a single post id (for inspection).');
    }

    protected function fire(): int
    {
        $dryRun = (bool) $this->input->getOption('dry-run');

        if (! $dryRun && ! $this->input->getOption('force')) {
            $this->error('Run with --force (or --dry-run to preview).');
            return 1;
        }

        if ($singleId = $this->input->getOption('id')) {
            return $this->fireSingle((int) $singleId, $dryRun);
        }

        $base = $this->db->table('posts')->where('content', 'LIKE', '%<LIST%');

        $total = (int) (clone $base)->count();
        $this->info(($dryRun ? '[dry-run] ' : '') . "Posts containing <LIST>: {$total}");

        $changed = 0;
        $unchanged = 0;
        $scanned = 0;

        $base->select('id', 'content')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$changed, &$unchanged, &$scanned, $dryRun) {
                foreach ($rows as $row) {
                    $scanned++;
                    $xml = (string) $row->content;
                    $fixed = PseudoListFixer::fix($xml);

                    if ($fixed === $xml) {
                        $unchanged++;
                        continue;
                    }

                    if (! $dryRun) {
                        $this->db->table('posts')->where('id', $row->id)->update(['content' => $fixed]);
                    }
                    $changed++;
                }
                $this->info("  scanned {$scanned} / changed {$changed}");
            }, 'id', 'id');

        $this->info('Done.');
        $this->info('  ' . ($dryRun ? 'would change' : 'changed') . " : {$changed}");
        $this->info("  unchanged (BBCode lists / no pseudo-list) : {$unchanged}");

        return 0;
    }

    private function fireSingle(int $id, bool $dryRun): int
    {
        $xml = $this->db->table('posts')->where('id', $id)->value('content');
        if ($xml === null) {
            $this->error("Post {$id} not found.");
            return 1;
        }

        $xml = (string) $xml;
        $fixed = PseudoListFixer::fix($xml);

        if ($fixed === $xml) {
            $this->info("Post {$id}: nothing to fix (no markdown list).");
            return 0;
        }

        if ($dryRun) {
            $this->info("Post {$id}: would be rewritten. New content:");
            $this->line($fixed);
            return 0;
        }

        $this->db->table('posts')->where('id', $id)->update(['content' => $fixed]);
        $this->info("Post {$id}: rewritten.");
        return 0;
    }
}
