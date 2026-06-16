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
            ->setDescription('Detects @username in posts and injects USERMENTION to turn it into a clickable mention.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Run with --force.');
            return 1;
        }

        $this->info('Loading username -> user_id map...');
        $userMap = [];
        foreach ($this->db->table('users')->select(['id', 'username'])->cursor() as $row) {
            $userMap[strtolower((string) $row->username)] = [
                'id' => (int) $row->id,
                'username' => (string) $row->username,
            ];
        }
        $this->info('  ' . count($userMap) . ' usernames loaded.');

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

                $this->info("  {$postsFixed} posts fixed, {$mentionsAdded} mentions");
            });

        $this->info('Done.');
        $this->info("  posts fixed : {$postsFixed}");
        $this->info("  mentions inserted: {$mentionsAdded}");

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

        // O s9e/TextFormatter só aplica os templates das tags quando a raiz do
        // documento é <r> (rich). Posts originalmente em texto puro têm raiz <t>
        // (plain) e, ao injetar <USERMENTION> dentro deles, a menção ficava CRUA
        // no HTML — aparecendo como texto "@Nero" em vez de virar link. Promove a
        // raiz para <r> sempre que o conteúdo final contiver uma tag de menção
        // renderizável. Cobre tanto a injeção feita agora quanto posts já
        // quebrados por execuções anteriores (idempotente).
        $new = self::promoteRootIfTagged($new);

        return [$new, array_values(array_unique($collected))];
    }

    /**
     * Promove a raiz <t> → <r> quando o documento contém uma tag de menção
     * (USERMENTION/POSTMENTION). Sem isso, o renderer do s9e trata o documento
     * como texto puro e emite a tag literalmente, sem virar link. Documentos que
     * já são <r>, ou <t> sem tags, são devolvidos intactos.
     */
    public static function promoteRootIfTagged(string $xml): string
    {
        if (! str_starts_with($xml, '<t>') || ! str_ends_with($xml, '</t>')) {
            return $xml;
        }

        if (! preg_match('/<(?:USER|POST)MENTION\b/', $xml)) {
            return $xml;
        }

        return '<r>' . substr($xml, 3, -4) . '</r>';
    }
}
