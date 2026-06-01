<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Comando de debug: dado um user_id, renderiza a `users.bio` (XML s9e) via
 * UserBioFormatter::render e mostra o HTML que sairia no endpoint bioHtml.
 * Útil pra verificar se o BBCode realmente está sendo emitido como <span style="color:#...">,
 * <em>, <strong> etc.
 */
class TestBioRenderCommand extends AbstractCommand
{
    public function __construct(
        protected ConnectionInterface $db,
        protected Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:test-bio-render')
            ->setDescription('Renderiza users.bio (XML s9e) e mostra o HTML resultante.')
            ->addArgument('user', InputArgument::REQUIRED, 'ID ou username do usuário.');
    }

    protected function fire(): int
    {
        $arg = $this->input->getArgument('user');
        $row = is_numeric($arg)
            ? $this->db->table('users')->where('id', (int) $arg)->first()
            : $this->db->table('users')->where('username', $arg)->first();

        if (! $row) {
            $this->error("Usuário {$arg} não encontrado.");
            return 1;
        }

        $bio = (string) ($row->bio ?? '');
        fwrite(STDOUT, "ID: {$row->id} | username: {$row->username}\n");
        fwrite(STDOUT, 'BIO XML (' . strlen($bio) . " bytes):\n");
        fwrite(STDOUT, substr($bio, 0, 800) . "\n\n---\n");

        // Resolve o formatter de bio preguiçosamente: a extensão fof/user-bio
        // pode não estar habilitada e o binding só existe nesse caso. Resolver
        // no construtor derrubaria todo o CLI do Flarum.
        if (! $this->container->bound('fof-user-bio.formatter')) {
            $this->error('A extensão fof/user-bio não está habilitada — não há formatter de bio disponível.');
            return 1;
        }

        $formatter = $this->container->make('fof-user-bio.formatter');

        try {
            $html = $formatter->render($bio);
            fwrite(STDOUT, "BIO HTML:\n");
            fwrite(STDOUT, $html . "\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "Render failed: " . $e->getMessage() . "\n");
            return 1;
        }

        return 0;
    }
}
