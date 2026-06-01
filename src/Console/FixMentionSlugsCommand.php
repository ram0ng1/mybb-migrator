<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Acrescenta o atributo `slug` nas tags USERMENTION existentes em posts.content
 * que foram injetadas pelo FixUserMentionsCommand sem ele.
 *
 * O template de USERMENTION renderiza `<a href="$PROFILE_URL{@slug}">` — sem
 * o slug, o link vai para `/u/` (a página da lista de usuários, que pode
 * redirecionar para o home). O slug padrão do Flarum é o username puro.
 */
class FixMentionSlugsCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:fix-mention-slugs')
            ->setDescription('Adds the slug attribute to existing USERMENTIONs to fix the profile link.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Run with --force.');
            return 1;
        }

        $totalFixed = 0;

        $this->db->table('posts')
            ->where('content', 'LIKE', '%<USERMENTION%')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$totalFixed) {
                foreach ($rows as $row) {
                    $old = (string) $row->content;
                    $new = self::addSlugs($old);

                    if ($new !== $old) {
                        $this->db->table('posts')->where('id', $row->id)->update(['content' => $new]);
                        $totalFixed++;
                    }
                }
                $this->info("  {$totalFixed} posts fixed");
            });

        $this->info('Done.');
        $this->info("  posts fixed : {$totalFixed}");

        return 0;
    }

    /**
     * Adiciona slug=displayname em qualquer USERMENTION que ainda não tenha
     * o atributo slug. Idempotente: re-rodar não causa efeito colateral.
     */
    public static function addSlugs(string $xml): string
    {
        return (string) preg_replace_callback(
            '#<USERMENTION([^>]*)>#',
            static function (array $m): string {
                $attrs = (string) $m[1];

                if (str_contains($attrs, ' slug=')) {
                    return (string) $m[0];
                }

                if (! preg_match('/\bdisplayname="([^"]+)"/', $attrs, $dm)) {
                    return (string) $m[0];
                }

                $name = (string) $dm[1];

                return sprintf('<USERMENTION%s slug="%s">', $attrs, $name);
            },
            $xml
        );
    }
}
