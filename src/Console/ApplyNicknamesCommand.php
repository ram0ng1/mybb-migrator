<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\Support\Charset;
use Symfony\Component\Console\Input\InputOption;

/**
 * Revive os nomes originais dos usuários renomeados em
 * `mybb_username_renames`, usando o esquema:
 *
 *   users.username  = slug kebab do original (rebus-knebus)  ← URL `/u/rebus-knebus`
 *   users.nickname  = nome original com chars/espaços (Rebus Knebus)  ← display
 *
 * Também restaura referências em posts.content:
 *   - `displayname="<slug>"` → `displayname="<original>"`
 *   - `author="<slug>"`      → `author="<original>"`
 *   - `username="<slug>"`    → `username="<original_kebab>"` (POSTMENTION.username
 *     espelha users.username, ou seja o kebab)
 *
 * Idempotente: roda em cima do que `mybb:fix-usernames` deixou.
 */
class ApplyNicknamesCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:apply-nicknames')
            ->setDescription('Promotes old_username → nickname and generates a kebab-case slug as username.')
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

        // Cache de usernames atuais pra resolver colisões.
        $existing = [];
        foreach ($this->db->table('users')->select('id', 'username')->get() as $row) {
            $existing[strtolower($row->username)] = (int) $row->id;
        }

        $renames = $this->db->table('mybb_username_renames')
            ->select('user_id', 'old_username', 'new_username')
            ->orderBy('id')
            ->get();

        $this->info('Historical renames to process: ' . $renames->count());

        // Map p/ Fase 2: SLUG_FIX_USERNAMES → ORIGINAL_USERNAME (display)
        //               SLUG_FIX_USERNAMES → KEBAB_SLUG       (url slug)
        $strtr = [];
        $updated = 0;
        $skipped = 0;

        foreach ($renames as $r) {
            // Repara mojibake (UTF-8 duplo) no nome original — "Ð”Ð¸Ð¼Ñ‹Ñ‡" → "Дымыч"
            $original = Charset::fix((string) $r->old_username);
            $fixSlug  = (string) $r->new_username;     // "RebusKnebus" (do fix-usernames)
            $kebab    = $this->kebab($original);       // "rebus-knebus"

            // Slugs muito curtos / com só dígitos / hífens são pouco úteis. Cai pra userN.
            if ($kebab === '' || strlen(str_replace('-', '', $kebab)) < 2) {
                $kebab = 'user' . $r->user_id;
            }

            // Resolução de colisão pro kebab — vê outros user ids
            $key = strtolower($kebab);
            if (isset($existing[$key]) && $existing[$key] !== (int) $r->user_id) {
                $base = $kebab;
                $i = 2;
                while (isset($existing[strtolower($kebab)])) {
                    $kebab = $base . '-' . $i;
                    $i++;
                }
            }

            // Atualiza o índice em memória (saída e entrada)
            unset($existing[strtolower($fixSlug)]);
            $existing[strtolower($kebab)] = (int) $r->user_id;

            if ($dryRun) {
                fwrite(STDOUT, sprintf("  [%d] %s | nickname=%s | username=%s\n", $r->user_id, $fixSlug, $original, $kebab));
            } else {
                $this->db->table('users')
                    ->where('id', $r->user_id)
                    ->update([
                        'nickname' => $original,
                        'username' => $kebab,
                    ]);
            }

            // strtr map — displayname/author querem o ORIGINAL,
            // username (do POSTMENTION/USERMENTION) quer o KEBAB.
            $fixSlugX  = htmlspecialchars($fixSlug, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $originalX = htmlspecialchars($original, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $kebabX    = htmlspecialchars($kebab, ENT_QUOTES | ENT_XML1, 'UTF-8');

            $strtr['displayname="' . $fixSlugX . '"'] = 'displayname="' . $originalX . '"';
            $strtr['author="' . $fixSlugX . '"']      = 'author="' . $originalX . '"';
            $strtr['username="' . $fixSlugX . '"']    = 'username="' . $kebabX . '"';

            $updated++;
            if ($updated % 100 === 0) {
                $this->info("  {$updated} users mapped…");
            }
        }

        $this->info("Phase 1: {$updated} users processed.");

        if ($dryRun) {
            $this->info('(dry-run — end)');
            return 0;
        }

        if (count($strtr) === 0) {
            $this->info('Nothing to replace in posts.');
            return 0;
        }

        // ── Fase 2: passa pelos posts UMA vez aplicando o strtr inteiro.
        $this->info('Phase 2: rewriting refs in posts.content…');

        // Filtro: posts contendo qualquer um dos fixSlugs.
        $patterns = [];
        foreach ($renames as $r) {
            $patterns[] = '%' . addcslashes(htmlspecialchars((string) $r->new_username, ENT_QUOTES | ENT_XML1, 'UTF-8'), '\\%_') . '%';
        }

        // Em chunks pra não ter UM WHERE gigante. Quebra em grupos de 200 LIKEs.
        // Usa o query builder (mesma conexão da Fase 1) em vez de PDO cru, que não
        // resolve a tabela pela conexão configurada do Flarum.
        $chunkSize = 200;
        $totalScanned = 0;
        $totalUpdated = 0;

        foreach (array_chunk($patterns, $chunkSize) as $idx => $patternsChunk) {
            $rows = $this->db->table('posts')
                ->select('id', 'content')
                ->where(function ($q) use ($patternsChunk) {
                    foreach ($patternsChunk as $p) {
                        $q->orWhere('content', 'LIKE', $p);
                    }
                })
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $totalScanned++;
                $newContent = strtr((string) $row->content, $strtr);
                if ($newContent !== $row->content) {
                    $this->db->table('posts')->where('id', $row->id)->update(['content' => $newContent]);
                    $totalUpdated++;
                }
            }
            $this->info("  chunk " . ($idx + 1) . ": scanned={$totalScanned} updated={$totalUpdated}");
        }

        $this->info('Done.');
        $this->info("  users           : {$updated}");
        $this->info("  posts scanned   : {$totalScanned}");
        $this->info("  posts updated   : {$totalUpdated}");

        return 0;
    }

    /**
     * Converte um username arbitrário para kebab-case ASCII:
     *  - normaliza Unicode pra ASCII (translit aproximada via iconv quando disponível)
     *  - lowercase
     *  - espaços / chars não-alfa → hífen
     *  - colapsa múltiplos hífens, trim
     */
    public function kebab(string $username): string
    {
        $s = $username;

        // Translit pra ASCII quando possível (perde acentos: Lévesque → Levesque).
        if (function_exists('iconv')) {
            $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($tr) && $tr !== '') {
                $s = $tr;
            }
        }

        $s = strtolower($s);
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');

        return $s;
    }
}
