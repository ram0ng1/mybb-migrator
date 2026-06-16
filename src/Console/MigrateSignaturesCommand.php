<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\BBCode\Converter;
use Ramon\MybbMigrator\Support\Charset;
use Symfony\Component\Console\Input\InputOption;

/**
 * Migra as assinaturas (`signature`) do MyBB para a extensão **fof/signature**,
 * que renderiza a assinatura no rodapé de cada post do autor (igual ao MyBB).
 *
 * Pipeline:
 *  1. Lê `mybb.dfsmybb_users.signature` (BBCode bruto).
 *  2. Aplica Converter (mojibake, emoji Tapatalk, normalização de BBCode).
 *  3. Passa pelo formatter do fof/signature (`fof-signature.formatter`) →
 *     XML do s9e/TextFormatter. O fof/signature guarda o conteúdo já PARSEADO
 *     (igual ao core: `parse()` no save, `render()` no display) na coluna
 *     `users.signature`.
 *  4. Grava o XML em `users.signature`.
 *
 * Também sobe `signature.maximum_char_limit` para um valor folgado, já que
 * algumas assinaturas do MyBB passam do limite padrão (500) — isso só afeta a
 * validação ao EDITAR pela UI; a renderização não trunca.
 *
 * Pré-requisitos: `composer require fof/signature`, `php flarum migrate` e a
 * extensão habilitada ANTES de rodar este comando (a coluna `users.signature`
 * precisa existir).
 *
 * Idempotente: regravar a mesma origem produz o mesmo XML.
 */
class MigrateSignaturesCommand extends AbstractCommand
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
            ->setName('mybb:migrate-signatures')
            ->setDescription('Migrates MyBB signatures into fof/signature (users.signature), preserving BBCode formatting.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.')
            ->addOption('char-limit', null, InputOption::VALUE_REQUIRED, 'Raise signature.maximum_char_limit to this value.', '2000');
        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Run with --force.');
            return 1;
        }

        if (! $this->db->getSchemaBuilder()->hasColumn('users', 'signature')) {
            $this->error('Column users.signature is missing. Run "composer require fof/signature", "php flarum migrate" and enable the extension first.');
            return 1;
        }

        // Sobe o limite de caracteres pra assinaturas longas do MyBB poderem ser
        // editadas pela UI (a renderização nunca trunca).
        $charLimit = (int) $this->input->getOption('char-limit');
        if ($charLimit > 0) {
            $this->settings->set('signature.maximum_char_limit', $charLimit);
            $this->info("signature.maximum_char_limit set to {$charLimit}.");
        }

        $mybb = $this->buildMybbDatabase($this->settings);
        $prefix = $mybb->prefix();

        // Limpa o cache do formatter pra ele reconfigurar os tags BBCode/Litedown.
        try {
            $this->container->make('fof-signature.formatter')->flush();
        } catch (\Throwable $e) {
            // ignora — não é fatal
        }

        /** @var \FoF\Signature\Formatter\SignatureFormatter $formatter */
        $formatter = $this->container->make('fof-signature.formatter');

        // MigrateUsersCommand preserva uid 1:1 como users.id.
        $this->info('Loading Flarum user ids …');
        $flarumIds = array_flip(array_map('intval', $this->db->table('users')->pluck('id')->all()));
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

            $raw = trim(Charset::fix((string) $row['signature']));

            if ($raw === '') {
                $this->db->table('users')->where('id', $uid)->update(['signature' => null]);
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

            $this->db->table('users')->where('id', $uid)->update(['signature' => $xml]);
            $updated++;

            if ($updated % 100 === 0) {
                $this->info("  {$updated} signatures migrated …");
            }
        }

        $this->info('Done.');
        $this->info("  migrated : {$updated}");
        $this->info("  emptied  : {$empty}");
        $this->info("  skipped  : {$skipped}");
        $this->info("  failed   : {$failed}");

        return 0;
    }
}
