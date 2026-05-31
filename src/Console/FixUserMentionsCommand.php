<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Detecta `@username` em texto puro dentro de posts.content e injeta
 * `<USERMENTION>` na XML para virar menção clicável.
 *
 * Regra de match: `@` precedido por NÃO-alfanumérico (não confunde com
 * email `user@host`) seguido por username válido. Lookup case-insensitive
 * na tabela `users`; se não existir, deixa o texto como está.
 *
 * Também popula `post_mentions_user` para o índice ficar consistente.
 */
class FixUserMentionsCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:fix-user-mentions')
            ->setDescription('Detecta @username em posts e injeta USERMENTION para virar menção clicável.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirma execução.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Rode com --force.');
            return 1;
        }

        $this->info('Carregando mapa username -> user_id...');
        $userMap = [];
        foreach ($this->db->table('users')->select(['id', 'username'])->cursor() as $row) {
            $userMap[strtolower((string) $row->username)] = [
                'id' => (int) $row->id,
                'username' => (string) $row->username,
            ];
        }
        $this->info('  ' . count($userMap) . ' usernames carregados.');

        $postsFixed = 0;
        $mentionsAdded = 0;

        $this->db->table('posts')
            ->where('content', 'LIKE', '%@%')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$postsFixed, &$mentionsAdded, $userMap) {
                $batch = [];

                foreach ($rows as $row) {
                    $old = (string) $row->content;
                    [$new, $ids] = self::process($old, $userMap);

                    if ($new !== $old) {
                        $this->db->table('posts')->where('id', $row->id)->update(['content' => $new]);
                        $postsFixed++;

                        foreach ($ids as $uid) {
                            $batch[] = ['post_id' => (int) $row->id, 'mentions_user_id' => $uid];
                            $mentionsAdded++;
                        }
                    }
                }

                if ($batch !== []) {
                    foreach (array_chunk($batch, 200) as $chunk) {
                        $this->db->table('post_mentions_user')->insertOrIgnore($chunk);
                    }
                }

                $this->info("  {$postsFixed} posts ajustados, {$mentionsAdded} menções");
            });

        $this->info('Concluído.');
        $this->info("  posts ajustados : {$postsFixed}");
        $this->info("  menções inseridas: {$mentionsAdded}");

        return 0;
    }

    /**
     * @param array<string, array{id:int, username:string}> $userMap
     * @return array{0:string, 1:array<int, int>}
     */
    public static function process(string $xml, array $userMap): array
    {
        $collected = [];

        $pieces = preg_split(
            '#(<(?:POSTMENTION|USERMENTION)\b[^>]*>.*?</(?:POSTMENTION|USERMENTION)>)#s',
            $xml,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        foreach ($pieces as $i => $piece) {
            if ($i % 2 !== 0) {
                continue;
            }
            $pieces[$i] = (string) preg_replace_callback(
                '/(?<![A-Za-z0-9_-])@(?:"([^"#<>]+)"|([A-Za-z0-9_-]+))/',
                static function (array $m) use ($userMap, &$collected): string {
                    $candidate = $m[1] !== '' ? $m[1] : (string) ($m[2] ?? '');
                    $lookup = strtolower(trim($candidate));

                    if ($lookup === '' || ! isset($userMap[$lookup])) {
                        return (string) $m[0];
                    }

                    $info = $userMap[$lookup];
                    $collected[] = $info['id'];

                    $name = htmlspecialchars($info['username'], ENT_QUOTES | ENT_XML1, 'UTF-8');

                    return sprintf(
                        '<USERMENTION displayname="%s" id="%d" slug="%s">@%s</USERMENTION>',
                        $name,
                        $info['id'],
                        $name,
                        $name
                    );
                },
                $piece
            );
        }

        $new = implode('', $pieces);

        return [$new, array_values(array_unique($collected))];
    }
}
