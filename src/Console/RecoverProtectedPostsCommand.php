<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Formatter\Formatter;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\BBCode\Converter;
use Symfony\Component\Console\Input\InputOption;

/**
 * Re-converte os posts cujo `content` contém o placeholder literal
 * `PROTECTED_N` (resíduo de um bug com null-byte no
 * StripOrphanBbcodeCommand que perdeu o conteúdo de elementos `<URL>`,
 * `<CODE>`, `<s>`, `<e>`).
 *
 * Para cada post afetado, lê `dfsmybb_posts.message` original do MyBB,
 * passa pelo Converter (que faz charset+Tapatalk emoji) e por
 * Formatter::parse, e grava o XML novo. Depois disso, os post-fixes
 * (fix-size-bbcode, fix-smilies, fix-font-bbcode, restore-quote-mentions,
 * fix-user-mentions, fix-mention-slugs) devem ser re-rodados para
 * re-aplicar as transformações pós-parse nestes posts.
 */
class RecoverProtectedPostsCommand extends AbstractCommand
{
    use MybbConnectionOptions;

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
            ->setName('mybb:recover-protected')
            ->setDescription('Re-converts posts corrupted with literal PROTECTED_N from the original MyBB.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.');
        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Run with --force.');
            return 1;
        }

        $mybb = $this->buildMybbDatabase($this->settings);
        $prefix = $mybb->prefix();

        $total = (int) $this->db->table('posts')->where('content', 'LIKE', '%PROTECTED_%')->count();
        $this->info("Posts to recover: {$total}");

        $stmt = $mybb->pdo()->prepare("SELECT message FROM {$prefix}posts WHERE pid = :pid");

        $done = 0;
        $skipped = 0;
        $failed = 0;

        $this->db->table('posts')
            ->where('content', 'LIKE', '%PROTECTED_%')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$done, &$skipped, &$failed, $stmt) {
                foreach ($rows as $row) {
                    $pid = (int) $row->id;
                    $stmt->execute(['pid' => $pid]);
                    $message = $stmt->fetchColumn();

                    if ($message === false) {
                        $skipped++;
                        continue;
                    }

                    $normalized = Converter::convert((string) $message);

                    try {
                        $content = $this->formatter->parse($normalized);
                    } catch (\Throwable $e) {
                        $failed++;
                        continue;
                    }

                    $this->db->table('posts')->where('id', $pid)->update(['content' => $content]);
                    $done++;
                }

                $this->info("  {$done} recovered, {$skipped} no MyBB source, {$failed} failed");
            }, 'id');

        $this->info('Done.');
        $this->info("  recovered          : {$done}");
        $this->info("  no MyBB source     : {$skipped}");
        $this->info("  parse failed       : {$failed}");
        $this->info('Next: re-run the idempotent fixes (fix-size-bbcode, fix-smilies, fix-font-bbcode, restore-quote-mentions, fix-user-mentions, fix-mention-slugs).');

        return 0;
    }
}
