<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Formatter\Formatter;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Aplica `Formatter::parse` no conteúdo dos posts de mensagens privadas
 * (discussões is_private=1). O MigrateMessagesCommand armazenou o BBCode
 * cru sem parsing, e o renderer falha com "Cannot load XML" pra todo PM.
 *
 * Pega o `posts.content` atual (que já passou pelo Converter — charset
 * e emoji do Tapatalk corrigidos) e converte em XML do s9e/TextFormatter.
 */
class FixPmParseCommand extends AbstractCommand
{
    public function __construct(
        protected ConnectionInterface $db,
        protected Formatter $formatter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:fix-pm-parse')
            ->setDescription('Aplica Formatter::parse no conteúdo dos posts de PMs (is_private=1) que ficaram com BBCode cru.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirma execução.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Rode com --force.');
            return 1;
        }

        $total = (int) $this->db->table('posts')
            ->join('discussions', 'discussions.id', '=', 'posts.discussion_id')
            ->where('discussions.is_private', 1)
            ->count();

        $this->info("Posts de PM a re-parsear: {$total}");

        $done = 0;
        $skipped = 0;
        $failed = 0;

        $this->db->table('posts')
            ->join('discussions', 'discussions.id', '=', 'posts.discussion_id')
            ->where('discussions.is_private', 1)
            ->select('posts.id', 'posts.content')
            ->orderBy('posts.id')
            ->chunkById(300, function ($rows) use (&$done, &$skipped, &$failed) {
                foreach ($rows as $row) {
                    $old = (string) $row->content;

                    if (str_starts_with(ltrim($old), '<r>') || str_starts_with(ltrim($old), '<t>')) {
                        $skipped++;
                        continue;
                    }

                    try {
                        $parsed = $this->formatter->parse($old);
                    } catch (\Throwable $e) {
                        $failed++;
                        continue;
                    }

                    $this->db->table('posts')->where('id', $row->id)->update(['content' => $parsed]);
                    $done++;
                }
                $this->info("  {$done} reparseados, {$skipped} já XML, {$failed} falharam");
            }, 'posts.id', 'id');

        $this->info('Concluído.');
        $this->info("  reparseados : {$done}");
        $this->info("  já XML      : {$skipped}");
        $this->info("  falha parse : {$failed}");

        return 0;
    }
}
