<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Reverte as interpretações automáticas do flarum/markdown que pegaram
 * `~~texto~~` como strikethrough e `~texto~` como subscript, quando no MyBB
 * eram apenas separadores visuais ou parte do título.
 *
 * Cada tag é substituída pela sua origem literal:
 *  <DEL><s>~~</s>X<e>~~</e></DEL>  ->  ~~X~~
 *  <SUB><s>~</s>X</SUB>            ->  ~X~
 *  <SUB><s>~</s>X<e>~</e></SUB>    ->  ~X~
 */
class RevertMdStrikeSubCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:revert-md-strike-sub')
            ->setDescription('Reverte <DEL> e <SUB> que vieram de ~~text~~ e ~text~ markdown (eram separadores MyBB).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirma execução.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Rode com --force.');
            return 1;
        }

        $totalPosts = 0;
        $totalTitles = 0;

        $this->info('Revertendo em posts.content...');
        $this->db->table('posts')
            ->where(function ($q) {
                $q->where('content', 'LIKE', '%<DEL>%')
                  ->orWhere('content', 'LIKE', '%<SUB>%');
            })
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$totalPosts) {
                foreach ($rows as $row) {
                    $old = (string) $row->content;
                    $new = self::revert($old);

                    if ($new !== $old) {
                        $this->db->table('posts')->where('id', $row->id)->update(['content' => $new]);
                        $totalPosts++;
                    }
                }
                $this->info("  posts ajustados: {$totalPosts}");
            });

        $this->info('Concluído.');
        $this->info("  posts ajustados      : {$totalPosts}");

        return 0;
    }

    public static function revert(string $xml): string
    {
        $xml = (string) preg_replace_callback(
            '#<DEL>(?:<s>~~</s>)?(.*?)(?:<e>~~</e>)?</DEL>#s',
            static fn (array $m): string => '~~' . $m[1] . '~~',
            $xml
        );

        $xml = (string) preg_replace_callback(
            '#<SUB>(?:<s>~</s>)?(.*?)(?:<e>~</e>)?</SUB>#s',
            static fn (array $m): string => '~' . $m[1] . '~',
            $xml
        );

        return $xml;
    }
}
