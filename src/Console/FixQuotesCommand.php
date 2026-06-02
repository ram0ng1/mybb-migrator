<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Injects a POSTMENTION tag before every existing QUOTE whose original `<s>`
 * carries MyBB's `pid='N'` attribute. This turns each migrated quote into a
 * clickable reply (with a "In reply to" link and a mention of the author)
 * without having to re-process the posts' BBCode.
 *
 * It also populates `post_mentions_post` so the mentions index stays
 * consistent.
 */
class FixQuotesCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:fix-quotes')
            ->setDescription('Injects POSTMENTION into migrated QUOTEs using the pid embedded in the <s>.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Run with --force.');
            return 1;
        }

        $this->info('Fixing migrated QUOTEs (pid -> POSTMENTION)...');
        $this->verbose('Verbose output on — use -v / -vv / -vvv for more detail.');

        $totalPostsFixed = 0;
        $totalMentions = 0;
        $chunkNum = 0;

        $this->db->table('posts')
            ->where('content', 'LIKE', '%pid=%')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$totalPostsFixed, &$totalMentions, &$chunkNum) {
                $chunkNum++;
                $firstId = (int) $rows->first()->id;
                $lastId = (int) $rows->last()->id;

                // Load only the posts quoted in this chunk, instead of the whole
                // `posts` table, keeping memory usage bounded.
                $postMap = $this->loadPostMapFor($rows);

                $this->verbose(sprintf(
                    '[chunk %d] ids %d..%d — %d posts, %d quoted pids loaded',
                    $chunkNum,
                    $firstId,
                    $lastId,
                    $rows->count(),
                    count($postMap)
                ));

                $mentionBatch = [];
                $chunkPostsFixed = 0;

                foreach ($rows as $row) {
                    $old = (string) $row->content;
                    [$new, $pids] = self::inject($old, $postMap);

                    if ($new !== $old) {
                        $this->db->table('posts')->where('id', $row->id)->update(['content' => $new]);
                        $totalPostsFixed++;
                        $chunkPostsFixed++;

                        $rowMentions = 0;
                        foreach ($pids as $pid) {
                            if (! isset($postMap[$pid])) {
                                continue;
                            }
                            $mentionBatch[] = ['post_id' => (int) $row->id, 'mentions_post_id' => $pid];
                            $totalMentions++;
                            $rowMentions++;
                        }

                        $this->veryVerbose(sprintf(
                            '    post #%d fixed — %d mention(s) [pids: %s]',
                            (int) $row->id,
                            $rowMentions,
                            implode(', ', $pids) ?: '-'
                        ));

                        // -vvv: dump the raw before/after XML so each migration
                        // can be inspected verbatim.
                        $this->debug(sprintf('    ── post #%d raw ──', (int) $row->id));
                        $this->debug('    BEFORE: ' . $old);
                        $this->debug('    AFTER : ' . $new);
                    }
                }

                if ($mentionBatch !== []) {
                    foreach (array_chunk($mentionBatch, 200) as $chunk) {
                        $this->db->table('post_mentions_post')->insertOrIgnore($chunk);
                    }
                }

                $this->info("  {$totalPostsFixed} posts fixed, {$totalMentions} mentions in index");
                $this->debug(sprintf(
                    '[chunk %d] +%d posts in this chunk | memory: %.1f MB (peak %.1f MB)',
                    $chunkNum,
                    $chunkPostsFixed,
                    memory_get_usage(true) / 1048576,
                    memory_get_peak_usage(true) / 1048576
                ));
            });

        $this->info('Done.');
        $this->info("  posts fixed            : {$totalPostsFixed}");
        $this->info("  mentions inserted      : {$totalMentions}");
        $this->debug(sprintf('  peak memory            : %.1f MB', memory_get_peak_usage(true) / 1048576));

        return 0;
    }

    /** Shown only with -v (or higher). */
    private function verbose(string $message): void
    {
        if ($this->output->isVerbose()) {
            $this->output->writeln($message);
        }
    }

    /** Shown only with -vv (or higher). */
    private function veryVerbose(string $message): void
    {
        if ($this->output->isVeryVerbose()) {
            $this->output->writeln($message);
        }
    }

    /** Shown only with -vvv. */
    private function debug(string $message): void
    {
        if ($this->output->isDebug()) {
            $this->output->writeln($message);
        }
    }

    /**
     * Idempotent: first strips old POSTMENTIONs that were injected without the
     * `number` attribute (broken ones), then injects the correct version with
     * displayname, id, number and discussionid.
     *
     * @param array<int, array{number:int, discussion_id:int}> $postMap
     * @return array{0: string, 1: array<int, int>}
     */
    public static function inject(string $xml, array $postMap): array
    {
        $collected = [];

        $xml = (string) preg_replace(
            '#<POSTMENTION displayname="[^"]*" id="\d+">@[^<]*</POSTMENTION>(?=<QUOTE)#',
            '',
            $xml
        );

        $new = (string) preg_replace_callback(
            '#<QUOTE author="([^"]+)"><s>\[quote=[^\]]*?\bpid=[\'"](\d+)[\'"]#i',
            static function (array $m) use (&$collected, $postMap): string {
                $author = (string) $m[1];
                $pid = (int) $m[2];
                $collected[] = $pid;
                $info = $postMap[$pid] ?? null;

                if ($info === null) {
                    $postmention = sprintf(
                        '<POSTMENTION deleted="1" displayname="%s" id="%d">@%s</POSTMENTION>',
                        $author,
                        $pid,
                        $author
                    );
                } else {
                    $postmention = sprintf(
                        '<POSTMENTION discussionid="%d" displayname="%s" id="%d" number="%d">@%s</POSTMENTION>',
                        $info['discussion_id'],
                        $author,
                        $pid,
                        $info['number'],
                        $author
                    );
                }

                return $postmention . $m[0];
            },
            $xml
        );

        return [$new, array_values(array_unique($collected))];
    }

    /**
     * Builds the pid -> (number, discussion_id) map only for the pids quoted in
     * the given set of posts. This keeps memory bounded to the current chunk,
     * instead of loading the whole `posts` table.
     *
     * @param iterable<object> $rows
     * @return array<int, array{number:int, discussion_id:int}>
     */
    private function loadPostMapFor(iterable $rows): array
    {
        $pids = [];
        foreach ($rows as $row) {
            foreach (self::extractPids((string) $row->content) as $pid) {
                $pids[$pid] = true;
            }
        }

        $postMap = [];
        foreach (array_chunk(array_keys($pids), 1000) as $idChunk) {
            foreach (
                $this->db->table('posts')
                    ->whereIn('id', $idChunk)
                    ->select(['id', 'number', 'discussion_id'])
                    ->cursor() as $p
            ) {
                $postMap[(int) $p->id] = [
                    'number' => (int) $p->number,
                    'discussion_id' => (int) $p->discussion_id,
                ];
            }
        }

        return $postMap;
    }

    /**
     * Extracts the pids referenced by a post's migrated QUOTEs, using the same
     * pattern that `inject()` recognizes.
     *
     * @return array<int, int>
     */
    public static function extractPids(string $xml): array
    {
        if (! preg_match_all(
            '#<QUOTE author="[^"]+"><s>\[quote=[^\]]*?\bpid=[\'"](\d+)[\'"]#i',
            $xml,
            $m
        )) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $m[1])));
    }
}
