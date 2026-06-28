<?php

namespace Ramon\MybbMigrator\Api\Controller;

use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\MybbMigrator\Gui\MybbBbcodeRenderer;
use Ramon\MybbMigrator\MybbDatabase;

/**
 * Comparação de fidelidade: para um post (pid = posts.id, IDs preservados),
 * devolve o BBCode cru do MyBB de origem + o HTML renderizado no Flarum + o link
 * (e, quando possível, o HTML renderizado) do post no site antigo. O painel
 * mostra os dois lado a lado para o admin conferir a formatação.
 */
class ComparePostController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db,
        protected Formatter $formatter,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $q = $request->getQueryParams();
        $random = ! empty($q['random']);
        $pid = isset($q['pid']) ? (int) $q['pid'] : 0;

        if ($random) {
            $pid = $this->randomPid() ?? 0;
        }

        if ($pid <= 0) {
            return new JsonResponse(['error' => 'no-pid'], 422);
        }

        $oldSite = rtrim((string) ($this->settings->get('mybb-migrator.old_site_url') ?: ''), '/');
        $oldUrl = $oldSite !== '' ? "{$oldSite}/showthread.php?pid={$pid}#pid{$pid}" : null;

        // --- lado Flarum ---
        $post = $this->db->table('posts')
            ->where('id', $pid)
            ->first(['id', 'content', 'number', 'discussion_id', 'type']);

        $flarumHtml = null;
        $title = null;
        $number = null;
        if ($post) {
            $number = $post->number ?? null;
            $title = $this->db->table('discussions')->where('id', $post->discussion_id)->value('title');
            $flarumHtml = $this->renderFlarum((string) ($post->content ?? ''));
        }

        // --- lado MyBB (origem) ---
        $mybbBbcode = null;
        $mybbError = null;
        try {
            $mybb = MybbDatabase::fromSettings($this->settings);
            $row = $mybb->select(
                'SELECT message FROM ' . $mybb->table('posts') . ' WHERE pid = ? LIMIT 1',
                [$pid]
            )->fetch();
            $mybbBbcode = $row ? (string) $row['message'] : null;
        } catch (\Throwable $e) {
            $mybbError = $e->getMessage();
        }

        // --- HTML do post antigo renderizado a partir do BANCO (sempre que houver
        //     BBCode); + tentativa best-effort de raspar o site no ar (fallback) ---
        $mybbHtml = $mybbBbcode !== null ? (new MybbBbcodeRenderer())->render($mybbBbcode) : null;
        $oldHtml = $oldUrl ? $this->fetchOldPostHtml($oldUrl, $pid) : null;

        return new JsonResponse([
            'pid'           => $pid,
            'title'         => $title,
            'number'        => $number,
            'found_flarum'  => (bool) $post,
            'found_mybb'    => $mybbBbcode !== null,
            'old_url'       => $oldUrl,
            'old_site'      => $oldSite,
            'old_html'      => $oldHtml,
            'mybb_html'     => $mybbHtml,
            'mybb_bbcode'   => $mybbBbcode,
            'mybb_error'    => $mybbError,
            'flarum_html'   => $flarumHtml,
        ]);
    }

    private function renderFlarum(string $content): ?string
    {
        if ($content === '') {
            return null;
        }
        try {
            // Conteúdo já é XML s9e (posts.content). null = sem contexto (preview).
            return $this->formatter->render($content, null);
        } catch (\Throwable $e) {
            // fallback: texto puro do XML
            return '<pre>' . htmlspecialchars(strip_tags($content), ENT_QUOTES) . '</pre>';
        }
    }

    private function randomPid(): ?int
    {
        // 1) tenta nos posts já migrados no Flarum
        $max = (int) $this->db->table('posts')->where('type', 'comment')->max('id');
        if ($max > 0) {
            $min = (int) $this->db->table('posts')->where('type', 'comment')->min('id');
            $pick = random_int($min ?: 1, $max);
            $id = $this->db->table('posts')
                ->where('type', 'comment')
                ->where('id', '>=', $pick)
                ->orderBy('id')
                ->value('id');

            return $id ? (int) $id : $max;
        }

        // 2) nada migrado ainda → sorteia na origem MyBB (pré-visualização)
        try {
            $mybb = MybbDatabase::fromSettings($this->settings);
            $smax = (int) $mybb->scalar('SELECT MAX(pid) FROM ' . $mybb->table('posts'));
            if ($smax <= 0) {
                return null;
            }
            $smin = (int) $mybb->scalar('SELECT MIN(pid) FROM ' . $mybb->table('posts'));
            $pick = random_int($smin ?: 1, $smax);
            $id = $mybb->scalar(
                'SELECT pid FROM ' . $mybb->table('posts') . ' WHERE pid >= ? ORDER BY pid LIMIT 1',
                [$pick]
            );

            return $id ? (int) $id : $smax;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Baixa a thread no site antigo e extrai o corpo do post (div id="pid_NNN"
     * no tema MyBB). Best-effort: retorna null em qualquer falha (offline, login
     * obrigatório, tema diferente).
     */
    private function fetchOldPostHtml(string $url, int $pid): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 8,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header'        => "User-Agent: Mozilla/5.0 (MybbMigrator compare)\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $html = @file_get_contents($url, false, $ctx);
        if ($html === false || $html === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // força utf-8
        $loaded = $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $loaded) {
            return null;
        }

        $xpath = new \DOMXPath($doc);
        // tema padrão MyBB: corpo do post em <div ... id="pid_NNNN">
        $node = $xpath->query('//*[@id="pid_' . $pid . '"]')->item(0);
        if (! $node) {
            return null;
        }

        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }

        return trim($inner) !== '' ? $inner : null;
    }
}
