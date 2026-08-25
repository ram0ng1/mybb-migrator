<?php

namespace Ramon\MybbMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\MybbMigrator\Support\ExitPool;
use Ramon\MybbMigrator\Support\ImageFetcher;

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
