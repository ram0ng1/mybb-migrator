<?php

namespace Ramon\MybbMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\MybbMigrator\Support\ImgurClient;

/**
 * A parte pura do imgur autenticado: a cota do dia (que precisa sobreviver ao
 * processo e zerar por dia UTC) e a leitura da resposta da API.
 */
class ImgurClientTest extends TestCase
{
    public function test_a_blank_client_id_means_not_configured(): void
    {
        $this->assertFalse((new ImgurClient('   '))->configured());
        $this->assertTrue((new ImgurClient('546c25a59c58ad7'))->configured());
    }

    public function test_the_daily_cap_is_counted_and_persisted(): void
    {
        $saved = [];
        $client = new ImgurClient('id', 3, null, function (string $state) use (&$saved): void {
            $saved[] = $state;
        });

        $this->assertSame(3, $client->remaining());
        $this->assertFalse($client->exhausted());

        $client->consume();
        $client->consume();
        $this->assertSame(1, $client->remaining());

        $client->consume();
        $this->assertTrue($client->exhausted(), 'a terceira chamada esgota o teto de 3');

        // Cada consumo persistiu "dia|usadas" — é isso que um run seguinte lê.
        $this->assertCount(3, $saved);
        $this->assertSame(ImgurClient::today() . '|3', $saved[2]);
    }

    public function test_a_persisted_state_from_today_carries_over(): void
    {
        $client = new ImgurClient('id', 10, ImgurClient::today() . '|7');

        $this->assertSame(7, $client->usedToday());
        $this->assertSame(3, $client->remaining());
        $this->assertSame(7, ImgurClient::usedIn(ImgurClient::today() . '|7'));
    }

    public function test_a_persisted_state_from_another_day_is_discarded(): void
    {
        $client = new ImgurClient('id', 10, '2000-01-01|9999');

        $this->assertSame(0, $client->usedToday());
        $this->assertSame(10, $client->remaining());
        $this->assertSame(0, ImgurClient::usedIn('2000-01-01|9999'));
        // Lixo no setting também não derruba nada.
        $this->assertSame(0, ImgurClient::usedIn('garbage'));
    }

    public function test_zero_cap_means_unlimited(): void
    {
        $client = new ImgurClient('id', 0);
        $client->consume();

        $this->assertFalse($client->exhausted());
        $this->assertSame(PHP_INT_MAX, $client->remaining());
    }

    /**
     * O imgur sabe melhor do que nós quanto sobrou: outro processo pode ter
     * gastado a cota da MESMA aplicação.
     */
    public function test_the_remote_client_remaining_header_ends_the_day(): void
    {
        $client = new ImgurClient('id', 10000);
        $client->observe(['x-ratelimit-clientremaining' => '0', 'x-ratelimit-clientreset' => '33529', 'x-ratelimit-userremaining' => '400']);

        $this->assertTrue($client->exhausted());
        $this->assertTrue($client->remoteExhausted(), 'foi o imgur quem disse, não o nosso teto');
        $this->assertSame(33529, $client->remoteReset());

        // O limite por IP (User) NÃO encerra o dia — volta em uma hora, e é
        // assunto do backoff do fetcher.
        $other = new ImgurClient('id', 10000);
        $other->observe(['x-ratelimit-userremaining' => '0']);
        $this->assertFalse($other->exhausted());
    }

    public function test_headers_carry_the_client_id(): void
    {
        $client = new ImgurClient('546c25a59c58ad7');

        $this->assertContains('Authorization: Client-ID 546c25a59c58ad7', $client->headers());
        $this->assertSame('https://api.imgur.com/3/image/T1Ji3QD', $client->endpoint('T1Ji3QD'));
    }

    public function test_a_successful_response_yields_the_direct_link(): void
    {
        $body = json_encode(['data' => ['id' => 'T1Ji3QD', 'type' => 'image/png', 'link' => 'https://i.imgur.com/T1Ji3QD.png'], 'success' => true, 'status' => 200]);

        $res = (new ImgurClient('id'))->parseResponse(200, (string) $body);

        $this->assertTrue($res['ok']);
        $this->assertSame('https://i.imgur.com/T1Ji3QD.png', $res['link']);
    }

    public function test_not_found_is_final(): void
    {
        $client = new ImgurClient('id');

        $byStatus = $client->parseResponse(404, '');
        $this->assertFalse($byStatus['ok']);
        $this->assertTrue($byStatus['not_found']);

        $byBody = $client->parseResponse(400, (string) json_encode(['data' => ['error' => 'Unable to find an image with the id, abcdefg'], 'success' => false, 'status' => 400]));
        $this->assertTrue($byBody['not_found']);
    }

    public function test_garbage_is_an_error_but_not_a_not_found(): void
    {
        $res = (new ImgurClient('id'))->parseResponse(502, '<html>bad gateway</html>');

        $this->assertFalse($res['ok']);
        $this->assertFalse($res['not_found'], 'um 5xx não pode aposentar a URL como imagem morta');
        $this->assertNotNull($res['error']);
    }

    /**
     * `/3/credits` é o único lugar em que o imgur diz "Invalid client_id" com
     * todas as letras (403). Em `/3/image/<id>` a mesma chave leva 429 +
     * ClientRemaining 0, indistinguível de cota esgotada.
     */
    public function test_credits_403_marks_the_client_id_invalid_for_good(): void
    {
        $client = new ImgurClient('wrong', 10000);

        $res = $client->parseCredits(403, (string) json_encode(['data' => ['error' => 'Invalid client_id', 'request' => '/3/credits', 'method' => 'GET'], 'success' => false, 'status' => 403]));

        $this->assertFalse($res['ok']);
        $this->assertTrue($res['invalid']);
        $this->assertStringContainsString('Invalid client_id', (string) $res['error']);
        $this->assertFalse($client->invalid(), 'parseCredits só lê; quem chama decide desligar');

        $client->markInvalid();
        $this->assertTrue($client->invalid());
        $this->assertTrue($client->exhausted(), 'chave recusada = nada mais é consultado');
        $this->assertSame(0, $client->remaining());
        $this->assertSame(0, $client->usedToday(), 'o pré-voo não conta na cota');
    }

    public function test_credits_200_reports_what_imgur_says_is_left(): void
    {
        $client = new ImgurClient('id', 10000);

        $res = $client->parseCredits(200, (string) json_encode(['data' => ['UserLimit' => 500, 'UserRemaining' => 500, 'UserReset' => 1789514547, 'ClientLimit' => 12500, 'ClientRemaining' => 12345], 'success' => true, 'status' => 200]));

        $this->assertTrue($res['ok']);
        $this->assertFalse($res['invalid']);
        $this->assertSame(12345, $res['remaining']);
        $this->assertSame(12500, $res['limit']);
        $this->assertSame(12345, $client->remoteRemaining());
        $this->assertFalse($client->exhausted());
    }

    public function test_credits_with_zero_remaining_ends_the_day(): void
    {
        $client = new ImgurClient('id', 10000);

        $res = $client->parseCredits(200, (string) json_encode(['data' => ['ClientLimit' => 12500, 'ClientRemaining' => 0], 'success' => true, 'status' => 200]));

        $this->assertTrue($res['ok'], 'a credencial foi aceita — é a cota que acabou');
        $this->assertTrue($client->remoteExhausted());
        $this->assertTrue($client->exhausted());
    }

    public function test_an_unexpected_credits_response_is_neither_ok_nor_invalid(): void
    {
        $res = (new ImgurClient('id'))->parseCredits(502, '<html>bad gateway</html>');

        $this->assertFalse($res['ok']);
        $this->assertFalse($res['invalid'], 'um 5xx no pré-voo não pode condenar a chave');
    }

    /**
     * O 429 que vem com `ClientRemaining: 0` não passa com backoff: é a cota da
     * aplicação (ou chave desconhecida). O fetcher usa isto para não retentar.
     */
    public function test_client_quota_gone_is_read_from_the_headers(): void
    {
        $this->assertTrue(ImgurClient::clientQuotaGone(['x-ratelimit-clientremaining' => '0']));
        $this->assertFalse(ImgurClient::clientQuotaGone(['x-ratelimit-clientremaining' => '499']));
        $this->assertFalse(ImgurClient::clientQuotaGone(['x-ratelimit-userremaining' => '0']), 'limite por IP volta em uma hora: esse vale retentar');
        $this->assertFalse(ImgurClient::clientQuotaGone([]));
    }
}
