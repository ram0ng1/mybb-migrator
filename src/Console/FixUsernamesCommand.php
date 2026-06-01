<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Corrige `users.username` removendo espaços e caracteres não suportados pelo
 * Flarum (que usa o slug do username diretamente na URL — `/u/{username}`).
 *
 * Estratégia em duas fases pra escalar com tabela `posts` grande:
 *  1. Renomeia TODOS os usernames inválidos primeiro, construindo um map
 *     [old => new]. Persiste em `mybb_username_renames` pra auditoria.
 *  2. Faz UMA passada na tabela `posts.content`, filtrando posts que
 *     contenham qualquer um dos nomes antigos (LIKE… OR LIKE… OR LIKE…),
 *     e em cada chunk faz `strtr` com o map inteiro.
 *
 * Isso evita o O(users × posts × LIKE) do shape ingênuo.
 */
class FixUsernamesCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:fix-usernames')
            ->setDescription('Removes spaces / invalid chars from users.username (and updates references in posts in batch).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm execution.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force') && ! $this->input->getOption('dry-run')) {
            $this->error('Run with --force or --dry-run.');
            return 1;
        }

        $dryRun = (bool) $this->input->getOption('dry-run');

        $this->ensureRenameTable();

        // Cache de usernames existentes pra resolução de colisão (lower-cased).
        $existing = [];
        foreach ($this->db->table('users')->select('id', 'username')->get() as $row) {
            $existing[strtolower($row->username)] = (int) $row->id;
        }

        $candidates = $this->db->table('users')
            ->select('id', 'username')
            ->where(function ($q) {
                $q->where('username', 'LIKE', '% %')
                  ->orWhere('username', 'REGEXP', '[^A-Za-z0-9_-]');
            })
            ->orderBy('id')
            ->get();

        $this->info('Users to fix: ' . $candidates->count());

        // ── Fase 1: renomeia users, monta o map [oldXmlEscaped => newXmlEscaped]
        // pra Fase 2 fazer strtr eficiente.
        $renameMap = [];
        $alreadyRenamed = 0;
        $skipped = 0;

        foreach ($candidates as $row) {
            $old = (string) $row->username;
            $new = $this->slugify($old);

            if ($new === $old) {
                $skipped++;
                continue;
            }

            // Fallback p/ usernames inteiramente compostos por chars não-ASCII
            // (cirílico mojibake, etc) — slug fica vazio. Gera `user{id}`.
            if ($new === '') {
                $new = 'user' . $row->id;
            }

            $key = strtolower($new);
            if (isset($existing[$key]) && $existing[$key] !== (int) $row->id) {
                $base = $new;
                $i = 2;
                while (isset($existing[strtolower($new)])) {
                    $new = $base . $i;
                    $i++;
                }
            }

            unset($existing[strtolower($old)]);
            $existing[strtolower($new)] = (int) $row->id;

            $renameMap[$old] = $new;

            if (! $dryRun) {
                $this->db->table('users')->where('id', $row->id)->update(['username' => $new]);
                $this->db->table('mybb_username_renames')->insert([
                    'user_id'      => (int) $row->id,
                    'old_username' => $old,
                    'new_username' => $new,
                ]);
            } else {
                fwrite(STDOUT, sprintf("  [%d] %s -> %s\n", $row->id, $old, $new));
            }

            $alreadyRenamed++;
            if ($alreadyRenamed % 100 === 0) {
                $this->info("  {$alreadyRenamed} usernames renamed…");
            }
        }

        $this->info("Phase 1 done: {$alreadyRenamed} renamed, {$skipped} skipped.");

        if ($dryRun) {
            $this->info('(dry-run — no changes persisted)');
            return 0;
        }

        // Inclui também usernames historicamente renomeados (de execuções
        // anteriores que não chegaram a atualizar os posts).
        foreach ($this->db->table('mybb_username_renames')->select('old_username', 'new_username')->get() as $r) {
            if (! isset($renameMap[$r->old_username])) {
                $renameMap[$r->old_username] = $r->new_username;
            }
        }

        if (count($renameMap) === 0) {
            $this->info('No references in posts to update.');
            return 0;
        }

        // ── Fase 2: passa pelos posts UMA vez, fazendo strtr com TODOS os
        // mapeamentos. Filtra com LIKE (OR) só pra reduzir o universo —
        // posts.content é o gargalo.
        $this->info('Phase 2: updating references in posts.content…');

        // strtr map: cada entrada vira `displayname="OLD" → displayname="NEW"`,
        // `author="OLD" → author="NEW"`, `username="OLD" → username="NEW"`.
        $strtr = [];
        $likeClauses = [];
        $bindings = [];
        foreach ($renameMap as $old => $new) {
            $oldEsc = htmlspecialchars($old, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $newEsc = htmlspecialchars($new, ENT_QUOTES | ENT_XML1, 'UTF-8');
            foreach (['displayname', 'author', 'username'] as $attr) {
                $strtr[$attr . '="' . $oldEsc . '"'] = $attr . '="' . $newEsc . '"';
            }
            // LIKE clauses — usa só o nome escapado uma vez por usuário pra reduzir filtro
            $likeClauses[] = 'content LIKE ?';
            $bindings[] = '%' . addcslashes($oldEsc, '\\%_') . '%';
        }

        $total = (int) $this->db->table('posts')
            ->whereRaw('(' . implode(' OR ', $likeClauses) . ')', $bindings)
            ->count();
        $this->info("Candidate posts: {$total}");

        $updated = 0;
        $scanned = 0;

        // chunkById com whereRaw — usa o connection direto pra evitar recriar bindings
        // a cada chunk. Fazemos manualmente com cursor.
        $sql = 'SELECT id, content FROM posts WHERE (' . implode(' OR ', $likeClauses) . ') ORDER BY id';
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);
        $stmt->setFetchMode(\PDO::FETCH_ASSOC);

        $batch = [];
        $flush = function (array $rows) use (&$updated): void {
            foreach ($rows as $r) {
                $this->db->table('posts')->where('id', $r['id'])->update(['content' => $r['content']]);
                $updated++;
            }
        };

        while ($row = $stmt->fetch()) {
            $scanned++;
            $newContent = strtr((string) $row['content'], $strtr);
            if ($newContent !== $row['content']) {
                $batch[] = ['id' => (int) $row['id'], 'content' => $newContent];
            }
            if (count($batch) >= 200) {
                $flush($batch);
                $batch = [];
                $this->info("  scanned={$scanned} updated={$updated}");
            }
        }
        if ($batch) {
            $flush($batch);
        }

        $this->info('Done.');
        $this->info("  renamed           : {$alreadyRenamed}");
        $this->info("  skipped           : {$skipped}");
        $this->info("  posts scanned     : {$scanned}");
        $this->info("  posts updated     : {$updated}");

        return 0;
    }

    /**
     * Mapeia username arbitrário para o subset aceito pelo Flarum.
     * Remove espaços e tudo que não for [A-Za-z0-9_-]; mantém capitalização.
     */
    public function slugify(string $username): string
    {
        $s = preg_replace('/\s+/u', '', $username) ?? '';
        $s = preg_replace('/[^A-Za-z0-9_-]/u', '', $s) ?? '';
        return $s;
    }

    /**
     * Cria a tabela de auditoria de renames (idempotente).
     */
    protected function ensureRenameTable(): void
    {
        $schema = $this->db->getSchemaBuilder();
        if ($schema->hasTable('mybb_username_renames')) {
            return;
        }
        $schema->create('mybb_username_renames', function ($table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->string('old_username', 191);
            $table->string('new_username', 191);
            $table->timestamp('created_at')->useCurrent();
        });
    }
}
