<?php

namespace Ramon\MybbMigrator\Support;

/**
 * Por onde cada download SAI.
 *
 * O backoff do {@see ImageFetcher} resolve o 429 do imgur esperando. Isso é
 * correto e é o que evita ser bloqueado — mas um acervo grande num único IP
 * leva horas justamente porque a espera é a única saída disponível. Com mais de
 * um IP, cada um tem a SUA cota no host de destino e o run anda em paralelo:
 * continua educado por IP, só que multiplicado.
 *
 * Duas formas de "outro IP", e o admin escreve a que tiver — a distinção sai do
 * formato da entrada, não de mais um botão:
 *
 *   203.0.113.9          IP LOCAL do servidor (CURLOPT_INTERFACE). É o caso de
 *   2001:db8::1          quem tem várias faixas apontadas para a mesma máquina:
 *                        a requisição sai com esse endereço de ORIGEM.
 *
 *   http://u:p@h:8080    PROXY (CURLOPT_PROXY). O IP visto pelo destino é o do
 *   socks5://h:1080      proxy. Aceita http, https, socks4, socks4a, socks5 e
 *   1.2.3.4:8080         socks5h; sem esquema e com porta assume-se http, que é
 *                        o mesmo padrão do curl.
 *
 * Lista vazia = um único exit "direto", ou seja, exatamente o comportamento de
 * antes desta classe existir.
 *
 * LIVENESS. Um IP que não está bound na máquina, ou um proxy que morreu, não
 * pode derrubar a migração inteira: cada exit acumula faltas de CONEXÃO e sai
 * do rodízio na terceira seguida (qualquer sucesso zera a ficha). Quando TODOS
 * caem, o pool fica exhausted e o fetcher devolve erro transitório — as imagens
 * ficam `deferred` e voltam no próximo run. O que ele nunca faz é cair no
 * direto por conta própria: quem configurou uma lista de IPs de saída não quer
 * descobrir depois que metade do acervo saiu pelo IP do servidor.
 */
final class ExitPool
{
    /** Faltas de conexão seguidas até um exit sair do rodízio. */
    private const STRIKES = 3;

    /** Esquemas de proxy que o curl entende. */
    private const PROXY_SCHEMES = ['http', 'https', 'socks4', 'socks4a', 'socks5', 'socks5h'];

    /** @var array<int, array{key: string, label: string, kind: string, value: string}> */
    private array $exits;

    /** @var array<string, int> key => faltas seguidas */
    private array $strikes = [];

    /** @var array<string, true> key => fora do rodízio */
    private array $dead = [];

    /**
     * @param array<int, array{key: string, label: string, kind: string, value: string}> $exits
     */
    private function __construct(array $exits)
    {
        $this->exits = $exits;
    }

    /** Pool de um exit só: o IP do próprio servidor, sem proxy. */
    public static function direct(): self
    {
        return new self([self::makeExit('direct', 'direct', '', 'direto')]);
    }

    /**
     * Lê a lista do admin. Separadores: vírgula, ponto e vírgula, espaço ou
     * quebra de linha — o campo é uma textarea e ninguém deve ter que lembrar
     * qual deles vale.
     */
    public static function fromList(?string $raw): self
    {
        $out = [];

        foreach (preg_split('/[\s,;]+/', (string) $raw) ?: [] as $entry) {
            $parsed = self::parse((string) $entry);

            if ($parsed !== null && ! isset($out[$parsed['key']])) {
                $out[$parsed['key']] = $parsed;
            }
        }

        return $out === [] ? self::direct() : new self(array_values($out));
    }

    /**
     * Exits ainda no rodízio.
     *
     * @return array<int, array{key: string, label: string, kind: string, value: string}>
     */
    public function live(): array
    {
        return array_values(array_filter(
            $this->exits,
            fn (array $exit): bool => ! isset($this->dead[$exit['key']])
        ));
    }

    public function exhausted(): bool
    {
        return $this->live() === [];
    }

    /** Há mais de uma saída de verdade? (um pool "direto" não rotaciona nada) */
    public function rotates(): bool
    {
        return count($this->exits) > 1;
    }

    public function count(): int
    {
        return count($this->exits);
    }

    /**
     * Registra uma falta de CONEXÃO — e só dela. Um HTTP 429 é o host de
     * destino falando, o que prova que o IP está vivo e funcionando; tirá-lo do
     * rodízio por isso seria desligar justamente o que o rodízio existe para
     * fazer.
     *
     * Devolve true quando esta foi a falta que tirou o exit do ar: é o momento
     * que merece uma linha no console.
     */
    public function strike(string $key): bool
    {
        if (isset($this->dead[$key])) {
            return false;
        }

        $this->strikes[$key] = ($this->strikes[$key] ?? 0) + 1;

        if ($this->strikes[$key] < self::STRIKES) {
            return false;
        }

        $this->dead[$key] = true;

        return true;
    }

    /** Um download que funcionou limpa a ficha do exit. */
    public function reward(string $key): void
    {
        unset($this->strikes[$key]);
    }

    public function labelFor(string $key): string
    {
        foreach ($this->exits as $exit) {
            if ($exit['key'] === $key) {
                return $exit['label'];
            }
        }

        return $key;
    }

    /** Uma linha para o resumo de rede do comando. */
    public function describe(): string
    {
        return implode(', ', array_column($this->exits, 'label'));
    }

    /**
     * @return null|array{key: string, label: string, kind: string, value: string}
     */
    private static function parse(string $entry): ?array
    {
        $entry = trim($entry);
        if ($entry === '') {
            return null;
        }

        if (str_contains($entry, '://')) {
            return self::proxy($entry);
        }

        // [2001:db8::1]:8080 — IPv6 entre colchetes só aparece acompanhado de
        // porta, logo é endereço de proxy e não IP de origem.
        if (str_starts_with($entry, '[')) {
            return self::proxy('http://' . $entry);
        }

        // Vários ':' sem colchete é IPv6 literal: 2001:db8::1 não é host:porta.
        if (substr_count($entry, ':') > 1) {
            return self::iface($entry);
        }

        if (preg_match('/^.+:\d{1,5}$/', $entry)) {
            return self::proxy('http://' . $entry);
        }

        return self::iface($entry);
    }

    /** @return array{key: string, label: string, kind: string, value: string} */
    private static function iface(string $address): array
    {
        return self::makeExit('if:' . strtolower($address), 'interface', $address, $address);
    }

    /** @return null|array{key: string, label: string, kind: string, value: string} */
    private static function proxy(string $url): ?array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '' || ! in_array($scheme, self::PROXY_SCHEMES, true)) {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT);

        // Sem credenciais: este é o rótulo que vai para o console e a chave que
        // identifica o exit, e senha de proxy não tem por que aparecer em log.
        $bare = $scheme . '://' . $host . ($port === null ? '' : ':' . $port);

        return self::makeExit('px:' . strtolower($bare), 'proxy', $url, $bare);
    }

    /** @return array{key: string, label: string, kind: string, value: string} */
    private static function makeExit(string $key, string $kind, string $value, string $label): array
    {
        return ['key' => $key, 'kind' => $kind, 'value' => $value, 'label' => $label];
    }
}
