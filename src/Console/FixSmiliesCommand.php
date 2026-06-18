<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Substitui smilies textuais do MyBB (códigos como `:happy2:`, `:rolleyes:`,
 * `:)`, `:cool:` etc.) por emojis Unicode em `posts.content`,
 * `discussions.title` e `users.signature` (fof/signature) — esta última
 * guardava os códigos literais, aparecendo como "smilie quebrado".
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
            ->setDescription('Replaces MyBB text smilies (:happy2:, :rolleyes:, etc.) with Unicode emojis.')
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

        $codesInDb = [];
        $rows = $mybb->select("SELECT `find` FROM {$prefix}smilies");
        while ($row = $rows->fetch()) {
            $code = trim((string) $row['find']);
            if ($code !== '') {
                $codesInDb[$code] = true;
            }
        }
        $this->info('Codes in dfsmybb_smilies: ' . count($codesInDb));

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
        $totalSignatures = 0;

        $this->info('Repairing posts.content...');
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
                $this->info("  posts fixed: {$totalPosts}");
            });

        $this->info('Repairing discussions.title...');
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
                $this->info("  titles fixed: {$totalTitles}");
            });

        // Assinaturas (fof/signature) — guardadas como XML s9e já parseado; os
        // códigos de smilie ficam como texto plano dentro do XML, então o mesmo
        // strtr aplica. mybb:fix-smilies só tratava posts/títulos, deixando as
        // assinaturas com `:cool:`, `:)` etc literais ("smilie quebrado").
        if ($this->db->getSchemaBuilder()->hasColumn('users', 'signature')) {
            $this->info('Repairing users.signature...');
            $this->db->table('users')
                ->whereNotNull('signature')
                ->where('signature', '<>', '')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use (&$totalSignatures, $map, $codes) {
                    foreach ($rows as $row) {
                        $old = (string) $row->signature;
                        if (! self::contentLikelyHasSmiley($old, $codes)) {
                            continue;
                        }
                        $new = strtr($old, $map);
                        if ($new !== $old) {
                            $this->db->table('users')->where('id', $row->id)->update(['signature' => $new]);
                            $totalSignatures++;
                        }
                    }
                    $this->info("  signatures fixed: {$totalSignatures}");
                });
        }

        $this->info('Done.');
        $this->info("  posts fixed        : {$totalPosts}");
        $this->info("  discussions fixed  : {$totalTitles}");
        $this->info("  signatures fixed   : {$totalSignatures}");

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
