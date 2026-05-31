<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use FoF\UserBio\Formatter\UserBioFormatter;
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
        protected UserBioFormatter $formatter,
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

        try {
            $html = $this->formatter->render($bio);
            fwrite(STDOUT, "BIO HTML:\n");
            fwrite(STDOUT, $html . "\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "Render failed: " . $e->getMessage() . "\n");
            return 1;
        }

        return 0;
    }
}
