<?php

namespace Ramon\MybbMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\MybbMigrator\Http\LegacyUrl;

/**
 * Os formatos de URL do MyBB que o redirecionamento reconhece. Uma URL que
 * não é do MyBB precisa voltar null: o middleware roda em toda requisição.
 */
class LegacyUrlTest extends TestCase
{
    /** @return array<string, array{string, array<string, mixed>, array{0: string, 1: int}|null}> */
    public static function urls(): array
    {
        return [
            'showthread tid'         => ['/showthread.php', ['tid' => '12'], [LegacyUrl::THREAD, 12]],
            'showthread pid'         => ['/showthread.php', ['pid' => '34'], [LegacyUrl::POST, 34]],
            'showthread tid+pid'     => ['/showthread.php', ['tid' => '12', 'pid' => '34'], [LegacyUrl::POST, 34]],
            'showthread lastpost'    => ['/showthread.php', ['tid' => '12', 'action' => 'lastpost'], [LegacyUrl::THREAD_LAST, 12]],
            'showthread newpost'     => ['/showthread.php', ['tid' => '12', 'action' => 'newpost'], [LegacyUrl::THREAD, 12]],
            'showthread page'        => ['/showthread.php', ['tid' => '12', 'page' => '3'], [LegacyUrl::THREAD, 12]],
            'printthread'            => ['/printthread.php', ['tid' => '12'], [LegacyUrl::THREAD, 12]],
            'subpasta'               => ['/forum/showthread.php', ['tid' => '12'], [LegacyUrl::THREAD, 12]],
            'maiúsculas'             => ['/ShowThread.php', ['tid' => '12'], [LegacyUrl::THREAD, 12]],
            'thread-N'               => ['/thread-12.html', [], [LegacyUrl::THREAD, 12]],
            'thread-N-page'          => ['/thread-12-page-3.html', [], [LegacyUrl::THREAD, 12]],
            'thread-N-post'          => ['/thread-12-post-34.html', [], [LegacyUrl::POST, 34]],
            'thread-N-lastpost'      => ['/thread-12-lastpost.html', [], [LegacyUrl::THREAD_LAST, 12]],
            'thread-N-newpost'       => ['/thread-12-newpost.html', [], [LegacyUrl::THREAD, 12]],
            'post-N'                 => ['/post-34.html', [], [LegacyUrl::POST, 34]],
            'forumdisplay'           => ['/forumdisplay.php', ['fid' => '5'], [LegacyUrl::FORUM, 5]],
            'forum-N'                => ['/forum-5.html', [], [LegacyUrl::FORUM, 5]],
            'forum-N-page'           => ['/forum-5-page-2.html', [], [LegacyUrl::FORUM, 5]],
            'member profile'         => ['/member.php', ['action' => 'profile', 'uid' => '7'], [LegacyUrl::USER, 7]],
            'user-N'                 => ['/user-7.html', [], [LegacyUrl::USER, 7]],
            'archive path thread'    => ['/archive/index.php/thread-12.html', [], [LegacyUrl::THREAD, 12]],
            'archive path forum'     => ['/archive/index.php/forum-5.html', [], [LegacyUrl::FORUM, 5]],
            // O PHP troca o "." da chave da query por "_".
            'archive query thread'   => ['/archive/index.php', ['thread-12_html' => ''], [LegacyUrl::THREAD, 12]],

            'home do Flarum'         => ['/', [], null],
            'tópico do Flarum'       => ['/d/12-titulo', [], null],
            'member sem profile'     => ['/member.php', ['action' => 'login'], null],
            'showthread sem id'      => ['/showthread.php', [], null],
            'id não numérico'        => ['/showthread.php', ['tid' => '12abc'], null],
            'id como array'          => ['/showthread.php', ['tid' => ['12']], null],
            'id zero'                => ['/forumdisplay.php', ['fid' => '0'], null],
            'index.php fora do arquivo' => ['/index.php', ['thread-12_html' => ''], null],
            'html qualquer'          => ['/thread-abc.html', [], null],
        ];
    }

    /**
     * @dataProvider urls
     * @param array<string, mixed> $query
     * @param array{0: string, 1: int}|null $expected
     */
    public function test_parse(string $path, array $query, ?array $expected): void
    {
        $this->assertSame($expected, LegacyUrl::parse($path, $query));
    }
}
