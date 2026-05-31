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
            ->setDescription('Restaura POSTMENTION (@"Author"#pPID) antes de cada QUOTE com pid embutido.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirma execução.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Rode com --force.');
            return 1;
        }

        $this->info('Carregando mapa pid -> (number, discussion_id)...');
        $postMap = [];
        foreach ($this->db->table('posts')->select(['id', 'number', 'discussion_id'])->cursor() as $row) {
            $postMap[(int) $row->id] = [
                'number' => (int) $row->number,
                'discussion_id' => (int) $row->discussion_id,
            ];
        }
        $this->info('  ' . count($postMap) . ' posts mapeados.');

        $totalFixed = 0;
        $totalMentions = 0;

        $this->db->table('posts')
            ->where('content', 'LIKE', '%pid=%')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$totalFixed, &$totalMentions, $postMap) {
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

                $this->info("  {$totalFixed} posts ajustados, {$totalMentions} menções no índice");
            });

        $this->info('Concluído.');
        $this->info("  posts ajustados        : {$totalFixed}");
        $this->info("  menções inseridas       : {$totalMentions}");

        return 0;
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
