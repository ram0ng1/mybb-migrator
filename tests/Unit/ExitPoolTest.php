<?php

namespace Ramon\MybbMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\MybbMigrator\Support\ExitPool;

/**
 * A leitura da lista de IPs de saída — a parte que o admin digita à mão e que,
 * se for interpretada errado, manda o download por onde não devia (ou por lugar
 * nenhum).
 */
class ExitPoolTest extends TestCase
{
    public function test_an_empty_list_is_a_single_direct_exit(): void
    {
        foreach ([null, '', '   ', " ,\n; "] as $raw) {
            $pool = ExitPool::fromList($raw);

            $this->assertSame(1, $pool->count());
            $this->assertFalse($pool->rotates(), 'um exit só não é rodízio');
            $this->assertSame('direct', $pool->live()[0]['kind']);
        }
    }

    public function test_a_bare_ip_binds_the_source_address(): void
    {
        $exit = ExitPool::fromList('203.0.113.9')->live()[0];

        $this->assertSame('interface', $exit['kind']);
        $this->assertSame('203.0.113.9', $exit['value']);
    }

    /**
     * `2001:db8::1` tem dois-pontos como `host:porta`, mas é um endereço, não um
     * proxy. Confundir os dois mandaria a conexão para uma porta inventada.
     */
    public function test_a_bare_ipv6_is_an_address_and_not_a_host_port_pair(): void
    {
        $exit = ExitPool::fromList('2001:db8::1')->live()[0];

        $this->assertSame('interface', $exit['kind']);
        $this->assertSame('2001:db8::1', $exit['value']);
    }

    public function test_a_host_with_a_port_is_an_http_proxy(): void
    {
        $exit = ExitPool::fromList('1.2.3.4:8080')->live()[0];

        $this->assertSame('proxy', $exit['kind']);
        $this->assertSame('http://1.2.3.4:8080', $exit['value']);
    }

    public function test_bracketed_ipv6_with_a_port_is_a_proxy(): void
    {
        $exit = ExitPool::fromList('[2001:db8::1]:8080')->live()[0];

        $this->assertSame('proxy', $exit['kind']);
    }

    public function test_proxy_schemes_are_kept_and_credentials_never_reach_the_label(): void
    {
        $exit = ExitPool::fromList('socks5://ramon:segredo@proxy.exemplo:1080')->live()[0];

        $this->assertSame('proxy', $exit['kind']);
        // O valor guarda a senha (o curl precisa dela)...
        $this->assertStringContainsString('segredo', $exit['value']);
        // ...o rótulo, que vai para o console, não.
        $this->assertSame('socks5://proxy.exemplo:1080', $exit['label']);
        $this->assertStringNotContainsString('segredo', $exit['label']);
        $this->assertStringNotContainsString('segredo', $exit['key']);
    }

    public function test_unusable_entries_are_dropped(): void
    {
        // ftp:// não é proxy que o curl saiba usar; sem host não há nada.
        $pool = ExitPool::fromList("ftp://x.exemplo\nhttp://");

        $this->assertSame(1, $pool->count());
        $this->assertSame('direct', $pool->live()[0]['kind']);
    }

    public function test_separators_are_interchangeable_and_duplicates_collapse(): void
    {
        $pool = ExitPool::fromList("203.0.113.9, 203.0.113.10;203.0.113.11\n203.0.113.9");

        $this->assertSame(3, $pool->count());
        $this->assertTrue($pool->rotates());
        $this->assertStringContainsString('203.0.113.11', $pool->describe());
    }

    /**
     * Três faltas SEGUIDAS de conexão tiram o exit do ar; um sucesso pelo
     * caminho zera a ficha — senão um proxy bom mas instável seria aposentado
     * ao longo de um run comprido.
     */
    public function test_three_consecutive_connection_faults_retire_an_exit(): void
    {
        $pool = ExitPool::fromList('203.0.113.9, 203.0.113.10');
        $key = $pool->live()[0]['key'];

        $this->assertFalse($pool->strike($key));
        $this->assertFalse($pool->strike($key));
        $this->assertTrue($pool->strike($key), 'a terceira falta derruba');

        $this->assertCount(1, $pool->live());
        $this->assertFalse($pool->exhausted(), 'ainda sobrou o outro IP');

        // Já caído, não anuncia queda de novo.
        $this->assertFalse($pool->strike($key));
    }

    public function test_a_success_clears_the_record(): void
    {
        $pool = ExitPool::fromList('203.0.113.9');
        $key = $pool->live()[0]['key'];

        $pool->strike($key);
        $pool->strike($key);
        $pool->reward($key);

        $this->assertFalse($pool->strike($key), 'a contagem recomeçou do zero');
        $this->assertCount(1, $pool->live());
    }

    public function test_the_pool_can_run_out(): void
    {
        $pool = ExitPool::fromList('203.0.113.9');
        $key = $pool->live()[0]['key'];

        foreach ([1, 2, 3] as $ignored) {
            $pool->strike($key);
        }

        $this->assertTrue($pool->exhausted());
        $this->assertSame([], $pool->live());
    }
}
