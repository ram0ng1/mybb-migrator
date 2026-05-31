<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Injeta uma tag POSTMENTION antes de cada QUOTE existente cujo `<s>` original
 * contém o atributo `pid='N'` do MyBB. Isso transforma cada citação migrada
 * em uma resposta clicável (com link "Foi respondido por" e mention do autor)
 * sem precisar re-processar o BBCode dos posts.
 *
 * Também popula `post_mentions_post` para que o índice de menções fique
 * consistente.
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
            ->setDescription('Injeta POSTMENTION nas QUOTEs migradas usando o pid embutido no <s>.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirma execução.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Rode com --force.');
            return 1;
        }

        $this->info('Carregando mapa pid -> (number, discussion_id) dos posts do Flarum...');
        $postMap = [];
        foreach ($this->db->table('posts')->select(['id', 'number', 'discussion_id'])->cursor() as $row) {
            $postMap[(int) $row->id] = [
                'number' => (int) $row->number,
                'discussion_id' => (int) $row->discussion_id,
            ];
        }
        $this->info('  ' . count($postMap) . ' posts mapeados.');

        $totalPostsFixed = 0;
        $totalMentions = 0;

        $this->db->table('posts')
            ->where('content', 'LIKE', '%pid=%')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$totalPostsFixed, &$totalMentions, $postMap) {
                $mentionBatch = [];

                foreach ($rows as $row) {
                    $old = (string) $row->content;
                    [$new, $pids] = self::inject($old, $postMap);

                    if ($new !== $old) {
                        $this->db->table('posts')->where('id', $row->id)->update(['content' => $new]);
                        $totalPostsFixed++;

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

                $this->info("  {$totalPostsFixed} posts ajustados, {$totalMentions} menções no índice");
            });

        $this->info('Concluído.');
        $this->info("  posts ajustados        : {$totalPostsFixed}");
        $this->info("  menções inseridas       : {$totalMentions}");

        return 0;
    }

    /**
     * Idempotente: primeiro remove POSTMENTIONs antigas que foram injetadas
     * sem o atributo `number` (quebradas), depois injeta a versão correta
     * com displayname, id, number e discussionid.
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
     * @return array<int, bool>
     */
    private function loadIdSet(string $table): array
    {
        $set = [];
        foreach ($this->db->table($table)->select('id')->cursor() as $row) {
            $set[(int) $row->id] = true;
        }
        return $set;
    }
}
