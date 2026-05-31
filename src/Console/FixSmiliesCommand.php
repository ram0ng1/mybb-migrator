<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Substitui smilies textuais do MyBB (códigos como `:happy2:`, `:rolleyes:`,
 * `:)`, `:cool:` etc.) por emojis Unicode em `posts.content` e
 * `discussions.title`.
 *
 * Lê a tabela `dfsmybb_smilies` do MyBB pra descobrir o conjunto completo
 * de códigos usados no fórum; cada código conhecido é mapeado para um
 * emoji próximo (a curadoria está em `self::DEFAULT_MAP`); códigos
 * desconhecidos são removidos para não ficarem como texto literal.
 *
 * Ordem importa: códigos mais longos são aplicados primeiro pra não cair
 * em conflito com prefixos (`:happy2:` antes de `:happy:`).
 */
class FixSmiliesCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    private const DEFAULT_MAP = [
        ':)'          => '🙂',
        ';)'          => '😉',
        ':cool:'      => '😎',
        ':D'          => '😃',
        ':P'          => '😛',
        ':rolleyes:'  => '🙄',
        ':shy:'       => '😳',
        ':('          => '🙁',
        ':at:'        => '😠',
        ':angel:'     => '😇',
        ':@'          => '😡',
        ':blush:'     => '😊',
        ':s'          => '😕',
        ':S'          => '😕',
        ':dodgy:'     => '😏',
        ':exclamation:' => '❗',
        ':heart:'     => '❤️',
        ':huh:'       => '😕',
        ':idea:'      => '💡',
        ':sleepy:'    => '😴',
        ':-/'         => '😕',
        ':cry:'       => '😢',
        ':sick:'      => '🤢',
        ':arrow:'     => '➡️',
        ':my:'        => '😊',
        ':party:'     => '🎉',
        ':tongue:'    => '😛',
        ':happy:'     => '😀',
        ':mad:'       => '😡',
        ':happy2:'    => '😄',
        ':winking:'   => '😉',
        ':evilgrin:'  => '😈',
        ':confused2:' => '😕',
        ':character:' => '😶',
        ':confused:'  => '😕',
        ':crybaby:'   => '😭',
        ':eek:'       => '😮',
        ':o'          => '😮',
        ':O'          => '😮',
        ':wink:'      => '😉',
        ':smile:'     => '🙂',
        ':lol:'       => '😆',
        ':love:'      => '😍',
        ':laugh:'     => '😂',
        ':thumbsup:'  => '👍',
        ':thumbup:'   => '👍',
        ':thumbsdown:' => '👎',
        ':thumbdown:' => '👎',
    ];

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:fix-smilies')
            ->setDescription('Substitui smilies textuais do MyBB (:happy2:, :rolleyes:, etc.) por emojis Unicode.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirma execução.');
        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Rode com --force.');
            return 1;
        }

        $mybb = $this->buildMybbDatabase($this->settings);
        $prefix = $mybb->prefix();

        $codesInDb = [];
        $rows = $mybb->select("SELECT `find` FROM {$prefix}smilies");
        while ($row = $rows->fetch()) {
            $code = trim((string) $row['find']);
            if ($code !== '') {
                $codesInDb[$code] = true;
            }
        }
        $this->info('Códigos no dfsmybb_smilies: ' . count($codesInDb));

        $map = self::DEFAULT_MAP;
        foreach (array_keys($codesInDb) as $code) {
            if (! isset($map[$code])) {
                $map[$code] = '';
            }
        }

        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $codes = array_keys($map);

        $totalPosts = 0;
        $totalTitles = 0;

        $this->info('Reparando posts.content...');
        $this->db->table('posts')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$totalPosts, $map, $codes) {
                foreach ($rows as $row) {
                    $old = (string) $row->content;
                    if (! self::contentLikelyHasSmiley($old, $codes)) {
                        continue;
                    }
                    $new = strtr($old, $map);
                    if ($new !== $old) {
                        $this->db->table('posts')->where('id', $row->id)->update(['content' => $new]);
                        $totalPosts++;
                    }
                }
                $this->info("  posts ajustados: {$totalPosts}");
            });

        $this->info('Reparando discussions.title...');
        $this->db->table('discussions')
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$totalTitles, $map, $codes) {
                foreach ($rows as $row) {
                    $old = (string) $row->title;
                    if (! self::contentLikelyHasSmiley($old, $codes)) {
                        continue;
                    }
                    $new = strtr($old, $map);
                    if ($new !== $old) {
                        $this->db->table('discussions')->where('id', $row->id)->update(['title' => $new]);
                        $totalTitles++;
                    }
                }
                $this->info("  títulos ajustados: {$totalTitles}");
            });

        $this->info('Concluído.');
        $this->info("  posts ajustados      : {$totalPosts}");
        $this->info("  discussões ajustadas : {$totalTitles}");

        return 0;
    }

    /**
     * @param array<int, string> $codes
     */
    private static function contentLikelyHasSmiley(string $content, array $codes): bool
    {
        if (! str_contains($content, ':')) {
            return false;
        }

        foreach ($codes as $code) {
            if ($code !== '' && str_contains($content, $code)) {
                return true;
            }
        }

        return false;
    }
}
