<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Restaura o padrão Flarum de citação para cada `<QUOTE>` que tem `pid=` na
 * fonte: injeta uma POSTMENTION antes do quote (`@"Author"#pPID` no unparse),
 * com `id`, `number` e `discussionid` corretos.
 *
 * Idempotente: primeiro remove qualquer POSTMENTION ou USERMENTION que esteja
 * imediatamente antes do `<QUOTE>` (essas são restos das passagens anteriores
 * de fix-quotes / fix-user-mentions), depois injeta a versão certa.
 */
class RestoreQuoteMentionsCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:restore-quote-mentions')
            ->setDescription('Restores POSTMENTION (@"Author"#pPID) before each QUOTE with an embedded pid.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Run with --force.');
            return 1;
        }

        $totalFixed = 0;
        $totalMentions = 0;

        $this->db->table('posts')
            ->where('content', 'LIKE', '%pid=%')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$totalFixed, &$totalMentions) {
                // Load only the posts quoted in this chunk, instead of the whole
                // `posts` table, keeping memory usage bounded.
                $postMap = $this->loadPostMapFor($rows);

                $mentionBatch = [];

                foreach ($rows as $row) {
                    $old = (string) $row->content;
                    [$new, $pids] = self::restore($old, $postMap);

                    if ($new !== $old) {
                        $this->db->table('posts')->where('id', $row->id)->update(['content' => $new]);
                        $totalFixed++;

                        foreach ($pids as $pid) {
                            if (! isset($postMap[$pid])) {
                                continue;
                            }
                            $mentionBatch[] = ['post_id' => (int) $row->id, 'mentions_post_id' => $pid];
                            $totalMentions++;
                        }
                    }
                }

                if ($mentionBatch !== []) {
                    foreach (array_chunk($mentionBatch, 200) as $chunk) {
                        $this->db->table('post_mentions_post')->insertOrIgnore($chunk);
                    }
                }

                $this->info("  {$totalFixed} posts fixed, {$totalMentions} mentions in index");
            });

        $this->info('Done.');
        $this->info("  posts fixed            : {$totalFixed}");
        $this->info("  mentions inserted       : {$totalMentions}");

        return 0;
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
     * pattern that `restore()` recognizes.
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

    /**
     * @param array<int, array{number:int, discussion_id:int}> $postMap
     * @return array{0:string, 1:array<int, int>}
     */
    public static function restore(string $xml, array $postMap): array
    {
        $collected = [];

        $xml = (string) preg_replace(
            '#(?:<POSTMENTION\b[^>]*>.*?</POSTMENTION>|<USERMENTION\b[^>]*>.*?</USERMENTION>)(?=<QUOTE)#s',
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
}
