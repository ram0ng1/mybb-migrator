<?php

namespace Ramon\MybbMigrator\Support;

/**
 * Acesso AUTENTICADO ao imgur, com cota diária.
 *
 * Sem credencial, o fetcher trata o imgur como qualquer CDN: pede
 * `i.imgur.com/<id>.jpg`, leva redirect para a página HTML quando a extensão
 * não bate, tenta as outras quatro extensões, e come 429 em bloco porque o
 * limite anônimo por IP é minúsculo. Com um Client-ID (grátis, em
 * https://api.imgur.com/oauth2/addclient) a conversa muda:
 *
 *   GET https://api.imgur.com/3/image/<id>
 *   Authorization: Client-ID <client_id>
 *
 * devolve o `link` DIRETO com a extensão certa (uma requisição em vez de até
 * cinco), diz com clareza quando a imagem não existe (404 = `failed` de vez, e
 * não "HTML devolvido"), e vem com uma cota conhecida: ~12.500 chamadas por
 * dia por aplicação. O teto configurável (padrão 10.000) fica abaixo disso de
 * propósito, para o run nunca ser o culpado de a aplicação inteira ser
 * bloqueada — e para sobrar folga se o admin usa o mesmo Client-ID em outro
 * lugar.
 *
 * O download do `link` em si (i.imgur.com) NÃO conta na cota da API; só a
 * consulta conta. E a cota é DIÁRIA, então o contador precisa sobreviver ao
 * processo: quem cria o cliente passa como ler e gravar o estado (na prática,
 * a tabela `settings` do Flarum). Dentro do processo a classe é pura — nada
 * de rede, nada de framework — justamente para ser testável.
 *
 * O imgur também tem um limite por IP de origem (~500/hora, `X-RateLimit-User*`)
 * e a API não atende IPv6. Nada disso mora aqui: o fetcher já respeita 429 +
 * `Retry-After` e força IPv4 nas chamadas à API. O que esta classe faz é só
 * contar, decidir se ainda pode, e ler a resposta.
 */
final class ImgurClient
{
    public const API = 'https://api.imgur.com/3/image/';

    /** Teto padrão de chamadas/dia — abaixo dos ~12.500 que o imgur concede. */
    public const DEFAULT_DAILY_CAP = 10000;

    private string $day;

    private int $used;

    /**
     * Quando o próprio imgur avisou que a cota da aplicação acabou
     * (`X-RateLimit-ClientRemaining: 0`), o dia está encerrado mesmo que o
     * nosso contador diga outra coisa — outro processo pode ter gastado.
     */
    private bool $remoteExhausted = false;

    /** Segundos até a cota da aplicação zerar, quando o imgur informou. */
    private ?int $remoteReset = null;

    /** @var null|callable(string): void */
    private $save;

    /**
     * @param string        $clientId Client-ID da aplicação registrada no imgur.
     * @param int           $dailyCap Máximo de chamadas à API por dia (UTC). 0 = sem teto.
     * @param ?string       $state    Estado persistido por um run anterior ({@see state()}).
     * @param null|callable $save     Recebe o novo estado a cada chamada consumida.
     */
    public function __construct(
        private string $clientId,
        private int $dailyCap = self::DEFAULT_DAILY_CAP,
        ?string $state = null,
        ?callable $save = null,
    ) {
        $this->clientId = trim($clientId);
        $this->dailyCap = max(0, $dailyCap);
        $this->save = $save;

        [$this->day, $this->used] = self::parse($state);
        $this->rollover();
    }

    public function configured(): bool
    {
        return $this->clientId !== '';
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function dailyCap(): int
    {
        return $this->dailyCap;
    }

    /** Chamadas já feitas hoje (UTC). */
    public function usedToday(): int
    {
        $this->rollover();

        return $this->used;
    }

    /** Quantas chamadas ainda cabem hoje; PHP_INT_MAX quando não há teto. */
    public function remaining(): int
    {
        if ($this->remoteExhausted) {
            return 0;
        }

        return $this->dailyCap === 0 ? PHP_INT_MAX : max(0, $this->dailyCap - $this->usedToday());
    }

    public function exhausted(): bool
    {
        return $this->configured() && $this->remaining() === 0;
    }

    /**
     * Foi o IMGUR quem disse que a cota acabou (e não o nosso teto)? Merece
     * mensagem própria: acontece também com um Client-ID errado — o imgur
     * responde 429 com `X-RateLimit-ClientRemaining: 0` para credencial
     * desconhecida, e "teto atingido (1/10000)" esconderia isso.
     */
    public function remoteExhausted(): bool
    {
        return $this->remoteExhausted;
    }

    public function remoteReset(): ?int
    {
        return $this->remoteReset;
    }

    /**
     * Cabeçalhos da consulta. O `Accept` é explícito porque a API responde
     * HTML para browsers em alguns erros.
     *
     * @return array<int, string>
     */
    public function headers(): array
    {
        return [
            'Authorization: Client-ID ' . $this->clientId,
            'Accept: application/json',
        ];
    }

    public function endpoint(string $id): string
    {
        return self::API . rawurlencode($id);
    }

    /**
     * Reserva uma chamada da cota do dia e persiste. Chamar ANTES da requisição:
     * se o processo morrer no meio, o pior que acontece é contar uma chamada a
     * mais — o erro do lado seguro.
     */
    public function consume(): void
    {
        $this->rollover();
        $this->used++;
        $this->persist();
    }

    /**
     * Lê o que o imgur disse sobre a cota nos cabeçalhos da resposta. Só o
     * limite da APLICAÇÃO (`Client`) encerra o dia; o limite por IP (`User`)
     * volta sozinho em uma hora e é assunto do backoff/429 do fetcher.
     *
     * @param array<string, string> $headers nomes em minúsculas
     */
    public function observe(array $headers): void
    {
        $remaining = $headers['x-ratelimit-clientremaining'] ?? null;

        if ($remaining !== null && ctype_digit(trim((string) $remaining)) && (int) $remaining <= 0) {
            $this->remoteExhausted = true;

            $reset = $headers['x-ratelimit-clientreset'] ?? null;
            $this->remoteReset = $reset !== null && ctype_digit(trim((string) $reset)) ? (int) $reset : null;
        }
    }

    /**
     * Interpreta a resposta da API.
     *
     *  - ok + link: baixe daqui.
     *  - not_found: a imagem não existe (falha DEFINITIVA, sem chutar extensões).
     *  - error: resposta inesperada (JSON quebrado, 5xx, sem link); quem chama
     *    decide se cai no caminho sem API.
     *
     * @return array{ok: bool, link: ?string, not_found: bool, error: ?string}
     */
    public function parseResponse(int $status, string $body): array
    {
        $json = json_decode($body, true);
        $data = is_array($json) ? ($json['data'] ?? null) : null;

        if ($status === 404) {
            return ['ok' => false, 'link' => null, 'not_found' => true, 'error' => 'imgur API: imagem não existe (404)'];
        }

        if (! is_array($json)) {
            return ['ok' => false, 'link' => null, 'not_found' => false, 'error' => 'imgur API: resposta não é JSON (HTTP ' . $status . ')'];
        }

        if (($json['success'] ?? false) !== true || ! is_array($data)) {
            $message = is_array($data) && isset($data['error'])
                ? (is_string($data['error']) ? $data['error'] : json_encode($data['error']))
                : 'HTTP ' . $status;

            // O imgur devolve 400/404 com `success: false` para ids inexistentes
            // ou malformados; ambos são "essa imagem não está lá".
            $notFound = in_array($status, [400, 404], true)
                || (is_string($message) && stripos($message, 'unable to find') !== false);

            return ['ok' => false, 'link' => null, 'not_found' => $notFound, 'error' => 'imgur API: ' . $message];
        }

        $link = $data['link'] ?? null;
        if (! is_string($link) || ! preg_match('#^https?://#i', $link)) {
            return ['ok' => false, 'link' => null, 'not_found' => false, 'error' => 'imgur API: resposta sem link'];
        }

        // Vídeo/GIFV: o `link` é .mp4 e o que serve como IMAGEM é o gif — mas o
        // fetcher só grava tipos de imagem; deixamos o link como veio e o
        // sniff decide (mp4 vira "tipo não suportado", que é o correto).
        return ['ok' => true, 'link' => $link, 'not_found' => false, 'error' => null];
    }

    /** Estado serializado para persistir: "YYYY-MM-DD|usadas". */
    public function state(): string
    {
        $this->rollover();

        return $this->day . '|' . $this->used;
    }

    /** Quantas chamadas um estado persistido acusa para HOJE (0 se for de outro dia). */
    public static function usedIn(?string $state): int
    {
        [$day, $used] = self::parse($state);

        return $day === self::today() ? $used : 0;
    }

    public static function today(): string
    {
        // A cota do imgur zera por dia UTC, não pelo fuso do servidor.
        return gmdate('Y-m-d');
    }

    /** @return array{0: string, 1: int} */
    private static function parse(?string $state): array
    {
        if (is_string($state) && preg_match('/^(\d{4}-\d{2}-\d{2})\|(\d+)$/', trim($state), $m)) {
            return [$m[1], (int) $m[2]];
        }

        return [self::today(), 0];
    }

    /** Virou o dia (UTC)? O contador recomeça. */
    private function rollover(): void
    {
        $today = self::today();
        if ($this->day !== $today) {
            $this->day = $today;
            $this->used = 0;
            $this->remoteExhausted = false;
            $this->remoteReset = null;
        }
    }

    private function persist(): void
    {
        if ($this->save !== null) {
            ($this->save)($this->state());
        }
    }
}
