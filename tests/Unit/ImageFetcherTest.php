<?php

namespace Ramon\MybbMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\MybbMigrator\Support\ExitPool;
use Ramon\MybbMigrator\Support\ImageFetcher;
use Ramon\MybbMigrator\Support\ImgurClient;

/**
 * Só a parte pura do fetcher: a geração de URLs candidatas. É ela que resolve o
 * caso relatado do imgur — `i.imgur.com/<id>.jpg` que redireciona para a página
 * `imgur.com/<id>` porque o objeto guardado é PNG.
 */
class ImageFetcherTest extends TestCase
{
    public function test_non_imgur_urls_have_a_single_candidate(): void
    {
        $fetcher = new ImageFetcher();

        $this->assertSame(
            ['https://example.com/foto.jpg'],
            $fetcher->candidates('https://example.com/foto.jpg')
        );
    }

    public function test_imgur_direct_url_gets_extension_variants(): void
    {
        $candidates = (new ImageFetcher())->candidates('https://i.imgur.com/T1Ji3QD.jpg');

        // A original vem primeiro (o caminho feliz não paga round-trip extra).
        $this->assertSame('https://i.imgur.com/T1Ji3QD.jpg', $candidates[0]);
        $this->assertContains('https://i.imgur.com/T1Ji3QD.png', $candidates);
        $this->assertContains('https://i.imgur.com/T1Ji3QD.gif', $candidates);
        $this->assertContains('https://i.imgur.com/T1Ji3QD.webp', $candidates);
        // Sem duplicar a .jpg que já era a original.
        $this->assertSame(array_unique($candidates), $candidates);
    }

    public function test_imgur_page_url_falls_back_to_the_direct_image(): void
    {
        $candidates = (new ImageFetcher())->candidates('https://imgur.com/T1Ji3QD');

        $this->assertSame('https://imgur.com/T1Ji3QD', $candidates[0]);
        $this->assertContains('https://i.imgur.com/T1Ji3QD.png', $candidates);
        $this->assertContains('https://i.imgur.com/T1Ji3QD.jpg', $candidates);
    }

    public function test_imgur_albums_and_galleries_are_not_guessed(): void
    {
        $fetcher = new ImageFetcher();

        // Não são imagens diretas: chutar extensões só geraria 404s.
        $this->assertCount(1, $fetcher->candidates('https://imgur.com/a/qQGfe'));
        $this->assertCount(1, $fetcher->candidates('https://imgur.com/gallery/qQGfe'));
    }

    /**
     * O caso relatado: o postimg de 2016 servia em `s20.postimg.org/<id>/<arquivo>`
     * e o host morreu; hoje o MESMO id responde em `i.postimg.cc/<id>/<arquivo>`.
     * A reescrita vem PRIMEIRO, porque a original é DNS morto e uma falha de
     * conexão encerra a lista.
     */
    public function test_legacy_postimg_urls_are_rewritten_to_the_modern_host_first(): void
    {
        $fetcher = new ImageFetcher();

        $this->assertSame(
            ['https://i.postimg.cc/65kcb53xp/sotd_2_6_2016_1.jpg', 'http://s20.postimg.org/65kcb53xp/sotd_2_6_2016_1.jpg'],
            $fetcher->candidates('http://s20.postimg.org/65kcb53xp/sotd_2_6_2016_1.jpg')
        );

        // Os outros hosts históricos da mesma família.
        $this->assertSame('https://i.postimg.cc/abc123/x.png', $fetcher->candidates('http://s3.postimage.org/abc123/x.png')[0]);
        $this->assertSame('https://i.postimg.cc/abc123/x.png', $fetcher->candidates('https://postimg.cc/abc123/x.png')[0]);
        $this->assertSame('https://i.postimg.cc/abc123/x.png', $fetcher->candidates('http://s11.postimg.io/abc123/x.png')[0]);
    }

    public function test_modern_postimg_and_page_urls_are_left_alone(): void
    {
        $fetcher = new ImageFetcher();

        // Já é o host atual: nada a reescrever.
        $this->assertSame(['https://i.postimg.cc/6q4vKG0s/576-07-04.jpg'], $fetcher->candidates('https://i.postimg.cc/6q4vKG0s/576-07-04.jpg'));
        // Página /image/<id>/ não diz o nome do arquivo — sem ele não há URL.
        $this->assertSame(['https://postimg.org/image/65kcb53xp/'], $fetcher->candidates('https://postimg.org/image/65kcb53xp/'));
    }

    public function test_imgur_ids_are_recognised_including_size_suffixes(): void
    {
        $fetcher = new ImageFetcher();

        $this->assertSame('T1Ji3QD', $fetcher->imgurId('https://i.imgur.com/T1Ji3QD.jpg'));
        $this->assertSame('T1Ji3QD', $fetcher->imgurId('https://imgur.com/T1Ji3QD'));
        // `...l` (large) e `...s` (small) são a MESMA imagem para a API.
        $this->assertSame('T1Ji3QD', $fetcher->imgurId('https://i.imgur.com/T1Ji3QDl.jpg'));
        $this->assertNull($fetcher->imgurId('https://imgur.com/a/qQGfe'));
        $this->assertNull($fetcher->imgurId('https://example.com/T1Ji3QD.jpg'));
    }

    /**
     * Cota esgotada: nada de rede — e a falha é TRANSITÓRIA (volta amanhã),
     * nunca `failed`.
     */
    public function test_an_exhausted_imgur_quota_defers_without_touching_the_network(): void
    {
        $client = new ImgurClient('id', 1, ImgurClient::today() . '|1');
        $notices = [];

        $res = (new ImageFetcher(retries: 0, hostDelayMs: 0))
            ->withImgur($client)
            ->onNotice(function (array $n) use (&$notices): void {
                $notices[] = $n;
            })
            ->fetchImage('https://i.imgur.com/T1Ji3QD.jpg');

        $this->assertFalse($res['ok']);
        $this->assertTrue($res['transient']);
        $this->assertFalse($res['defer'], 'cota não é "host fora do ar": não vai para a fila do fim do run');
        $this->assertStringContainsString('cota diária', (string) $res['error']);
        $this->assertSame(1, $client->usedToday(), 'nenhuma chamada foi gasta');
        $this->assertCount(1, $notices);
        $this->assertSame('imgur_cap', $notices[0]['kind']);
    }

    /**
     * Client-ID recusado no pré-voo: nenhuma imagem do imgur toca a rede, o
     * aviso é o de credencial (não o de cota), e a falha é transitória — a
     * imagem provavelmente existe; é a chave que está errada.
     */
    public function test_an_invalid_imgur_client_id_defers_without_touching_the_network(): void
    {
        $client = new ImgurClient('wrong', 10000);
        $client->markInvalid();
        $notices = [];

        $fetcher = (new ImageFetcher(retries: 0, hostDelayMs: 0))
            ->withImgur($client)
            ->onNotice(function (array $n) use (&$notices): void {
                $notices[] = $n;
            });

        $first = $fetcher->fetchImage('https://i.imgur.com/T1Ji3QD.jpg');
        $second = $fetcher->fetchImage('https://imgur.com/um4r8CZ');

        $this->assertFalse($first['ok']);
        $this->assertTrue($first['transient']);
        $this->assertStringContainsString('Client-ID recusado', (string) $first['error']);
        $this->assertFalse($second['ok']);
        $this->assertSame(0, $client->usedToday(), 'nenhuma chamada foi gasta');
        $this->assertCount(1, $notices, 'o aviso sai uma vez por run, não por imagem');
        $this->assertSame('imgur_invalid', $notices[0]['kind']);
    }

    /**
     * Sem credencial configurada não há o que verificar.
     */
    public function test_verify_imgur_is_a_no_op_without_a_client(): void
    {
        $this->assertNull((new ImageFetcher())->verifyImgur());
        $this->assertNull((new ImageFetcher())->withImgur(new ImgurClient(''))->verifyImgur());
    }

    /**
     * TLS verificado por padrão: desligar tem de ser um pedido explícito
     * (--insecure), nunca o estado inicial.
     */
    public function test_tls_verification_is_on_unless_explicitly_disabled(): void
    {
        $this->assertTrue((new ImageFetcher())->verifiesTls());
        $this->assertFalse((new ImageFetcher(verifyTls: false))->verifiesTls());
    }

    public function test_an_unconfigured_imgur_client_is_ignored(): void
    {
        $fetcher = (new ImageFetcher())->withImgur(new ImgurClient(''));

        $this->assertNull($fetcher->imgur());
    }

    /**
     * Host que não atende: com o adiamento ligado, a PRIMEIRA falha de conexão
     * já volta marcada `defer` (sem gastar retentativas), e a segunda desarma o
     * host — a partir daí as URLs dele voltam sem rede.
     */
    public function test_connection_failures_are_deferred_and_trip_the_host(): void
    {
        if (! function_exists('curl_init')) {
            $this->markTestSkipped('sem extensão curl');
        }

        $retries = 0;
        $notices = [];

        $fetcher = (new ImageFetcher(timeout: 2, maxBytes: 4096, retries: 3, hostDelayMs: 0))
            ->deferConnectionFailures()
            ->onRetry(function () use (&$retries): void {
                $retries++;
            })
            ->onNotice(function (array $n) use (&$notices): void {
                $notices[] = $n;
            });

        // Porta fechada no loopback: conexão recusada na hora (errno 7).
        $first = $fetcher->fetchImage('http://127.0.0.1:1/a.jpg');
        $this->assertFalse($first['ok']);
        $this->assertTrue($first['transient']);
        $this->assertTrue($first['defer']);
        $this->assertSame(0, $retries, 'nenhuma retentativa inline');
        $this->assertSame([], $fetcher->trippedHosts(), 'uma falha ainda não desarma');

        $second = $fetcher->fetchImage('http://127.0.0.1:1/b.jpg');
        $this->assertTrue($second['defer']);
        $this->assertSame(['127.0.0.1'], $fetcher->trippedHosts());
        $this->assertCount(1, $notices);
        $this->assertSame('host_tripped', $notices[0]['kind']);

        $third = $fetcher->fetchImage('http://127.0.0.1:1/c.jpg');
        $this->assertTrue($third['defer']);
        $this->assertStringContainsString('pulado', (string) $third['error']);

        // Rearmado, o host volta a ser tentado de verdade.
        $fetcher->resetHosts();
        $this->assertSame([], $fetcher->trippedHosts());
    }

    /**
     * Com o adiamento DESLIGADO (--no-defer, ou a passada final), o
     * comportamento antigo: retentativas inline até o host desarmar.
     */
    public function test_without_deferral_connection_failures_are_retried_inline_until_the_host_trips(): void
    {
        if (! function_exists('curl_init')) {
            $this->markTestSkipped('sem extensão curl');
        }

        $retries = 0;

        $res = (new ImageFetcher(timeout: 2, maxBytes: 4096, retries: 5, hostDelayMs: 0))
            ->onRetry(function () use (&$retries): void {
                $retries++;
            })
            ->fetchImage('http://127.0.0.1:1/a.jpg');

        // Duas falhas seguidas desarmam o host e cortam as retentativas ali.
        $this->assertSame(1, $retries);
        $this->assertTrue($res['transient']);
        $this->assertTrue($res['defer']);
    }

    public function test_extension_is_derived_from_the_mime_type(): void
    {
        $this->assertSame('jpg', ImageFetcher::extensionFor('image/jpeg'));
        $this->assertSame('png', ImageFetcher::extensionFor('IMAGE/PNG'));
        $this->assertSame('webp', ImageFetcher::extensionFor('image/webp'));

        // HTML (a página de "imagem removida") nunca vira arquivo de imagem.
        $this->assertNull(ImageFetcher::extensionFor('text/html'));
        $this->assertNull(ImageFetcher::extensionFor(null));
    }

    /**
     * O backoff cresce em dobro e cada espera cai na metade de cima da janela:
     * nunca ~0 (bater de novo no host que acabou de recusar) nem além do teto.
     */
    public function test_backoff_grows_exponentially_within_the_jitter_window(): void
    {
        foreach ([0, 1, 2, 3, 4] as $attempt) {
            $window = 2.0 * (2 ** $attempt);

            for ($i = 0; $i < 25; $i++) {
                $delay = ImageFetcher::backoffDelay(2.0, $attempt);

                $this->assertGreaterThanOrEqual($window / 2, $delay);
                $this->assertLessThanOrEqual($window, $delay);
            }
        }
    }

    public function test_backoff_is_capped(): void
    {
        // 2 * 2^20 seria mais de um dia de espera por uma imagem.
        $this->assertLessThanOrEqual(60.0, ImageFetcher::backoffDelay(2.0, 20));
        $this->assertGreaterThanOrEqual(30.0, ImageFetcher::backoffDelay(2.0, 20));
    }

    /**
     * O ponto do jitter: duas filas que falharam no mesmo instante não podem
     * voltar no mesmo instante.
     */
    public function test_backoff_does_not_return_the_same_delay_every_time(): void
    {
        $seen = [];
        for ($i = 0; $i < 30; $i++) {
            $seen[] = ImageFetcher::backoffDelay(2.0, 3);
        }

        $this->assertGreaterThan(1, count(array_unique($seen)));
    }

    /**
     * Uma retentativa não pode ser silenciosa: o console (e o do painel) precisa
     * dizer que está esperando, e por quê.
     */
    public function test_transient_failures_are_reported_to_the_retry_listener(): void
    {
        if (! function_exists('curl_init')) {
            $this->markTestSkipped('sem extensão curl');
        }

        $seen = [];

        // Porta fechada no loopback: conexão recusada na hora — um erro de rede
        // transitório de verdade, sem depender de host externo.
        (new ImageFetcher(timeout: 2, maxBytes: 4096, retries: 1, hostDelayMs: 0))
            ->onRetry(function (array $retry) use (&$seen): void {
                $seen[] = $retry;
            })
            ->fetchImage('http://127.0.0.1:1/foto.jpg');

        $this->assertCount(1, $seen, 'uma tentativa falha + retries=1 => um aviso');
        $this->assertSame(1, $seen[0]['attempt']);
        $this->assertSame(1, $seen[0]['of']);
        $this->assertSame('127.0.0.1', $seen[0]['host']);
        $this->assertNotSame('', $seen[0]['error']);
        $this->assertGreaterThan(0, (float) $seen[0]['wait']);
    }

    /**
     * Sem IP de saída disponível o fetcher NÃO cai no IP do servidor: devolve
     * falha transitória, que deixa as imagens `deferred` para o próximo run.
     * Sair pelo endereço que a configuração justamente evita seria o pior dos
     * dois mundos — silencioso e irreversível.
     */
    public function test_an_exhausted_pool_fails_transiently_instead_of_going_direct(): void
    {
        $pool = ExitPool::fromList('203.0.113.9');
        $key = $pool->live()[0]['key'];
        foreach ([1, 2, 3] as $ignored) {
            $pool->strike($key);
        }

        $res = (new ImageFetcher(retries: 0, hostDelayMs: 0, exits: $pool))
            ->fetchImage('https://i.imgur.com/T1Ji3QD.jpg');

        $this->assertFalse($res['ok']);
        $this->assertTrue($res['transient'], 'deferred, não failed');
        $this->assertStringContainsString('nenhum IP de saída', (string) $res['error']);
    }

    /**
     * Cada imagem que precisa de espelho começa por um espelho diferente do
     * anterior — sem isso o primeiro da lista levaria sempre o primeiro tiro
     * e seria ele a ganhar um rate limit próprio.
     */
    public function test_mirrors_rotate_the_starting_proxy(): void
    {
        $fetcher = new ImageFetcher();

        $first = $fetcher->mirrors('https://example.com/foto.jpg');
        $second = $fetcher->mirrors('https://example.com/foto.jpg');
        $third = $fetcher->mirrors('https://example.com/foto.jpg');

        $this->assertCount(3, $first);
        $this->assertSame(array_unique($first), $first);
        // A mesma janela de 3, só que começando num ponto diferente.
        $this->assertNotSame($first[0], $second[0]);
        $this->assertNotSame($second[0], $third[0]);
        $this->assertSame($first[0], $fetcher->mirrors('https://example.com/foto.jpg')[0], 'o rodízio dá a volta');
    }

    /**
     * O serveproxy recodifica tudo para AVIF — inclusive o placeholder
     * `removed.png` do imgur, sem deixar rastro no final_url. Por isso ele
     * fica de fora do rodízio quando o alvo é o imgur.
     */
    public function test_serveproxy_is_excluded_from_imgur_mirrors(): void
    {
        $fetcher = new ImageFetcher();

        $mirrors = $fetcher->mirrors('https://i.imgur.com/T1Ji3QD.jpg');

        $this->assertCount(2, $mirrors);
        foreach ($mirrors as $mirror) {
            $this->assertStringNotContainsString('serveproxy.com', $mirror);
        }

        // Fora do imgur os três entram no rodízio.
        $this->assertCount(3, $fetcher->mirrors('https://example.com/foto.jpg'));
    }

    public function test_mirror_urls_are_built_with_the_right_encoding(): void
    {
        $fetcher = new ImageFetcher();

        $joined = implode(' ', $fetcher->mirrors('https://example.com/foto.jpg'));

        // DuckDuckGo e serveproxy recebem a URL como parâmetro (urlencoded).
        $this->assertStringContainsString(
            'external-content.duckduckgo.com/iu/?u=' . rawurlencode('https://example.com/foto.jpg'),
            $joined
        );
        $this->assertStringContainsString(
            'serveproxy.com/?url=' . rawurlencode('https://example.com/foto.jpg'),
            $joined
        );
        // O Wayback recebe a URL crua, colada depois do timestamp.
        $this->assertStringContainsString(
            'web.archive.org/web/20000000000000if_/https://example.com/foto.jpg',
            $joined
        );
    }

    /**
     * Insistir no IP que acabou de ser recusado é esperar à toa: a tentativa
     * seguinte tem de sair por outro endereço do rodízio.
     */
    public function test_the_next_attempt_leaves_through_a_different_exit(): void
    {
        if (! function_exists('curl_init')) {
            $this->markTestSkipped('sem extensão curl');
        }

        $used = [];

        // Dois IPs de origem que não existem nesta máquina (TEST-NET-3), e um
        // destino em loopback: falha imediata, sem tocar a rede.
        (new ImageFetcher(
            timeout: 2,
            maxBytes: 4096,
            retries: 2,
            hostDelayMs: 0,
            exits: ExitPool::fromList('203.0.113.9, 203.0.113.10'),
        ))
            ->onRetry(function (array $retry) use (&$used): void {
                $used[] = $retry['exit'];
            })
            ->fetchImage('http://127.0.0.1:1/foto.jpg');

        $this->assertCount(2, $used);
        $this->assertNotSame($used[0], $used[1], 'a segunda tentativa trocou de IP');
    }
}
