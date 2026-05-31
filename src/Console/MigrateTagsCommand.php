<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Ramon\MybbMigrator\Support\Charset;
use Symfony\Component\Console\Input\InputOption;

/**
 * Migra a árvore de fóruns do MyBB para as tags do flarum/tags preservando o
 * fid como id da tag. Categorias e fóruns viram tags; redirects (linkto não
 * vazio) são ignorados — Flarum não tem esse conceito. A hierarquia pai/filho
 * é preservada via tags.parent_id.
 */
class MigrateTagsCommand extends AbstractCommand
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
            ->setName('mybb:tags')
            ->setDescription('Migra os fóruns do MyBB para tags do Flarum preservando IDs e hierarquia.')
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

        $stmt = $mybb->select(
            "SELECT fid, pid, name, description, linkto, disporder, type
             FROM {$prefix}forums
             ORDER BY pid, disporder, fid"
        );

        $this->db->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $now = date('Y-m-d H:i:s');
            $inserted = 0; $skipped = 0;
            $slugTaken = [];

            while ($row = $stmt->fetch()) {
                $fid = (int) $row['fid'];
                $linkto = trim((string) ($row['linkto'] ?? ''));

                if ($linkto !== '') {
                    $skipped++;
                    continue;
                }

                $name = Charset::fix((string) $row['name']);
                $description = Charset::fix((string) ($row['description'] ?? ''));
                $pid = (int) ($row['pid'] ?? 0);
                $disporder = (int) ($row['disporder'] ?? 0);

                if ($name === '') {
                    $name = "Tag {$fid}";
                }

                $slug = $this->uniqueSlug($name, $slugTaken);

                $tagRow = [
                    'id'          => $fid,
                    'parent_id'   => $pid > 0 ? $pid : null,
                    'position'    => max(0, $disporder - 1),
                    'color'       => '#' . str_pad(dechex(mt_rand(0x303030, 0xCFCFCF)), 6, '0', STR_PAD_LEFT),
                    'name'        => mb_substr($name, 0, 100),
                    'slug'        => $slug,
                    'description' => $description !== '' ? mb_substr($description, 0, 65000) : null,
                    'is_hidden'   => 0,
                    'is_restricted' => 0,
                    'discussion_count' => 0,
                    'created_at'  => $now,
                ];

                $this->db->table('tags')->insert($tagRow);
                $inserted++;
            }
        } finally {
            $this->db->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->info("Tags migradas: {$inserted} (redirects pulados: {$skipped})");

        return 0;
    }

    /**
     * Gera um slug único usando Str::slug e desambiguando com -2, -3, ...
     *
     * @param array<string, bool> $taken
     */
    private function uniqueSlug(string $name, array &$taken): string
    {
        $base = Str::slug($name) ?: 'tag';
        $candidate = $base;
        $i = 1;

        while (isset($taken[$candidate])) {
            $i++;
            $candidate = $base . '-' . $i;
        }

        $taken[$candidate] = true;
        return $candidate;
    }
}
