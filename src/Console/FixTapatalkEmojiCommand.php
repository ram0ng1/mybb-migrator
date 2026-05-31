<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\Support\TapatalkEmoji;
use Symfony\Component\Console\Input\InputOption;

/**
 * Re-corrige os emojis Tapatalk já migrados com a fórmula antiga (bugada).
 * Para cada código presente na tabela curada `TapatalkEmoji::MAP`, relê o source
 * MyBB para achar os posts que continham `[emojiN]`, recomputa o caractere
 * ERRADO que a fórmula legada gravou e o substitui pelo caractere CORRETO no
 * conteúdo Flarum desses posts. Só toca códigos mapeados → seguro e idempotente.
 *
 * Para uma correção completa, expanda `TapatalkEmoji::MAP` com a tabela oficial
 * de índices Tapatalk→Unicode e rode novamente.
 */
class FixTapatalkEmojiCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:fix-tapatalk-emoji')
            ->setDescription('Re-corrige emojis Tapatalk migrados (substitui o char errado pelo correto nos posts).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Aplica de fato.');
        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        if (TapatalkEmoji::MAP === []) {
            $this->error('TapatalkEmoji::MAP está vazio — adicione mapeamentos antes de rodar.');
            return 1;
        }

        $force = (bool) $this->input->getOption('force');
        $mybb = $this->buildMybbDatabase($this->settings);
        $prefix = $mybb->prefix();

        $totalFixed = 0;

        foreach (TapatalkEmoji::MAP as $n => $cp) {
            $legacy = TapatalkEmoji::legacyChar((int) $n);
            $correct = mb_chr((int) $cp, 'UTF-8') ?: '';
            if ($legacy === '' || $correct === '' || $legacy === $correct) {
                continue;
            }

            $token = "[emoji{$n}]";
            $pids = [];
            foreach ($mybb->cursor("SELECT pid FROM {$prefix}posts WHERE message LIKE " . $mybb->pdo()->quote('%' . $token . '%')) as $row) {
                $pids[] = (int) $row['pid'];
            }

            if ($pids === []) {
                $this->info("emoji{$n}: nenhum post de origem.");
                continue;
            }

            $fixed = 0;
            foreach (array_chunk($pids, 500) as $chunk) {
                $rows = $this->db->table('posts')
                    ->whereIn('id', $chunk)
                    ->where('content', 'LIKE', '%' . $legacy . '%')
                    ->get(['id', 'content']);

                foreach ($rows as $r) {
                    $new = str_replace($legacy, $correct, (string) $r->content);
                    if ($new !== $r->content) {
                        if ($force) {
                            $this->db->table('posts')->where('id', $r->id)->update(['content' => $new]);
                        }
                        $fixed++;
                    }
                }
            }

            $this->info("emoji{$n} ({$legacy} → {$correct}): {$fixed} posts" . ($force ? ' corrigidos' : ' (dry-run)'));
            $totalFixed += $fixed;
        }

        $this->info(($force ? '[APLICADO]' : '[DRY-RUN, use --force]') . " total={$totalFixed}");
        return 0;
    }
}
