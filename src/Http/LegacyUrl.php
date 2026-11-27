<?php

declare(strict_types=1);

namespace Ramon\MybbMigrator\Http;

/**
 * Reconhece as URLs do MyBB e diz a QUE elas apontam: um post, um tópico,
 * um fórum ou um usuário, pelo id. Não consulta nada: quem transforma o id
 * em endereço do Flarum é o LegacyRedirectMiddleware. Isso só funciona porque
 * a migração preserva os ids (pid → posts.id, tid → discussions.id,
 * fid → tags.id, uid → users.id).
 *
 * Formatos cobertos (com e sem as "URLs amigáveis" do MyBB e o arquivo):
 *
 *   showthread.php?tid=1 | ?pid=2 | ?tid=1&action=lastpost | ?tid=1&pid=2
 *   thread-1.html | thread-1-page-3.html | thread-1-post-2.html
 *   thread-1-lastpost.html | thread-1-newpost.html | post-2.html
 *   printthread.php?tid=1
 *   forumdisplay.php?fid=5 | forum-5.html | forum-5-page-2.html
 *   member.php?action=profile&uid=7 | user-7.html
 *   archive/index.php/thread-1.html | archive/index.php?thread-1.html
 *   archive/index.php/forum-5.html | archive/index.php?forum-5.html
 */
final class LegacyUrl
{
    public const POST = 'post';
    public const THREAD = 'thread';
    public const THREAD_LAST = 'thread-last';
    public const FORUM = 'forum';
    public const USER = 'user';

    /**
     * @param array<string, mixed> $query
     * @return array{0: string, 1: int}|null
     */
    public static function parse(string $path, array $query): ?array
    {
        $file = strtolower(basename(rtrim($path, '/')));

        // O arquivo do MyBB também aceita o alvo na query sem valor:
        // /archive/index.php?thread-12.html.
        if ($file === 'index.php' && str_contains(strtolower($path), '/archive/')) {
            foreach (array_keys($query) as $key) {
                $match = self::friendly(strtolower(str_replace('_', '.', (string) $key)));
                if ($match !== null) {
                    return $match;
                }
            }

            return null;
        }

        $int = static fn (string $key): int => isset($query[$key]) && is_scalar($query[$key]) && ctype_digit((string) $query[$key]) ? (int) $query[$key] : 0;

        switch ($file) {
            case 'showthread.php':
            case 'printthread.php':
                if ($int('pid') > 0) {
                    return [self::POST, $int('pid')];
                }
                if ($int('tid') > 0) {
                    $action = is_string($query['action'] ?? null) ? strtolower($query['action']) : '';

                    return [$action === 'lastpost' ? self::THREAD_LAST : self::THREAD, $int('tid')];
                }

                return null;

            case 'forumdisplay.php':
                return $int('fid') > 0 ? [self::FORUM, $int('fid')] : null;

            case 'member.php':
                $action = is_string($query['action'] ?? null) ? strtolower($query['action']) : '';

                return $action === 'profile' && $int('uid') > 0 ? [self::USER, $int('uid')] : null;
        }

        return self::friendly($file);
    }

    /** @return array{0: string, 1: int}|null */
    private static function friendly(string $file): ?array
    {
        if (preg_match('/^thread-(\d+)-post-(\d+)\.html$/', $file, $m)) {
            return [self::POST, (int) $m[2]];
        }
        if (preg_match('/^thread-(\d+)-lastpost\.html$/', $file, $m)) {
            return [self::THREAD_LAST, (int) $m[1]];
        }
        if (preg_match('/^thread-(\d+)(?:-page-\d+|-newpost)?\.html$/', $file, $m)) {
            return [self::THREAD, (int) $m[1]];
        }
        if (preg_match('/^post-(\d+)\.html$/', $file, $m)) {
            return [self::POST, (int) $m[1]];
        }
        if (preg_match('/^forum-(\d+)(?:-page-\d+)?\.html$/', $file, $m)) {
            return [self::FORUM, (int) $m[1]];
        }
        if (preg_match('/^user-(\d+)\.html$/', $file, $m)) {
            return [self::USER, (int) $m[1]];
        }

        return null;
    }
}
