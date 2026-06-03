<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Formatter\Formatter;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\BBCode\Converter;
use Ramon\MybbMigrator\Support\Charset;
use Symfony\Component\Console\Input\InputOption;

/**
 * Re-importa as assinaturas (`signature`) do MyBB preservando a formatação
 * BBCode original (cor, bold, italic, etc). Faz o pipeline completo:
 *  1. Lê `mybb.dfsmybb_users.signature` original (BBCode bruto).
 *  2. Aplica Converter (mojibake, emoji, normalização de quotes).
 *  3. Passa por `Formatter::parse` → XML do s9e/TextFormatter (`<r>...</r>`).
 *  4. Grava no `users.bio` do Flarum.
 *
 * Também garante a setting `fof-user-bio.allowFormatting=true` para que o
 * fof/user-bio renderize o XML como HTML via `bioHtml`. O bundle JS
 * `ramon/post-signature` lê o `bioHtml` e renderiza com `m.trust()` no
 * rodapé de cada post — preservando bold/italic/cor/etc.
 */
class ReimportSignaturesCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    public function __construct(
        protected ConnectionInterface $db,
        protected Container $container,
        protected SettingsRepositoryInterface $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:reimport-signatures')
            ->setDescription('Re-imports users.bio from MyBB with BBCode → s9e XML (preserves color/bold/italic).')
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

        $this->settingTrue('fof-user-bio.allowFormatting');

        // Limpa o cache do formatter de bio pra ele ler `allowFormatting`
        // atualizado e re-configurar os tags BBCode/Litedown.
        try {
            $this->container->make('cache.store')->forget('fof-user-bio.formatter');
        } catch (\Throwable $e) {
            // ignora — não é fatal
        }

        // Usa o UserBioFormatter (não o Formatter principal) pra que o XML
        // gerado seja exatamente o que o fof/user-bio entende ao renderizar
        // bioHtml. O UserBioFormatter configura BBCode + Litedown.
        $formatter = $this->container->make('fof-user-bio.formatter');

        // MigrateUsersCommand preserva uid 1:1 como users.id. Buscamos só ids
        // que existem no Flarum pra ignorar usuários que não foram migrados.
        $this->info('Loading Flarum user ids …');
        $flarumIds = $this->db->table('users')->pluck('id')->all();
        $flarumIds = array_flip(array_map('intval', $flarumIds));
        $this->info('  ' . count($flarumIds) . ' users in Flarum.');

        $rows = $mybb->cursor("SELECT uid, signature FROM `{$prefix}users` WHERE signature IS NOT NULL AND signature <> '' ORDER BY uid");

        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $empty = 0;

        foreach ($rows as $row) {
            $uid = (int) $row['uid'];
            if (! isset($flarumIds[$uid])) {
                $skipped++;
                continue;
            }

            $raw = (string) $row['signature'];
            $raw = Charset::fix($raw);
            $raw = trim($raw);

            if ($raw === '') {
                $this->db->table('users')->where('id', $uid)->update(['bio' => null]);
                $empty++;
                continue;
            }

            try {
                $converted = Converter::convert($raw);
                $xml = $formatter->parse($converted);
            } catch (\Throwable $e) {
                $failed++;
                continue;
            }

            $this->db->table('users')->where('id', $uid)->update(['bio' => $xml]);
            $updated++;

            if ($updated % 100 === 0) {
                $this->info("  {$updated} bios re-imported …");
            }
        }

        $this->info('Done.');
        $this->info("  re-imported : {$updated}");
        $this->info("  emptied     : {$empty}");
        $this->info("  skipped     : {$skipped}");
        $this->info("  failed      : {$failed}");
        $this->info('Reminder: fof-user-bio.allowFormatting is enabled — bioHtml will come rendered.');

        return 0;
    }

    /**
     * Garante que uma setting booleana fique como '1'. Insere se não existir.
     */
    protected function settingTrue(string $key): void
    {
        $exists = $this->db->table('settings')->where('key', $key)->exists();
        if ($exists) {
            $this->db->table('settings')->where('key', $key)->update(['value' => '1']);
        } else {
            $this->db->table('settings')->insert(['key' => $key, 'value' => '1']);
        }
    }
}
