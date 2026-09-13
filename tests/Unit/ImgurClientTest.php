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
        $client->observe(['x-ratelimit-clientremaining' => '0', 'x-ratelimit-userremaining' => '400']);

        $this->assertTrue($client->exhausted());

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
}
