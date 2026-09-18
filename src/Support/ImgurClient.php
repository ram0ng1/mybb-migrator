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
 * dia por aplicação. O teto local é opcional e fica desligado por padrão;
 * o admin pode definir um valor menor quando compartilhar o Client-ID com
 * outro processo.
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

    /**
     * Pré-voo da credencial. NÃO conta na cota, e é o único endpoint que
     * distingue com clareza "Client-ID inválido" (403 `Invalid client_id`) de
     * "cota acabou": em `/3/image/<id>` o imgur responde 429 com
     * `X-RateLimit-ClientRemaining: 0` para os DOIS casos — e um run que só
     * olhasse ali gastaria minutos de backoff numa chave que nunca vai passar.
     */
    public const CREDITS = 'https://api.imgur.com/3/credits';

    /** 0 = sem teto local; a API do Imgur continua aplicando a quota dela. */
    public const DEFAULT_DAILY_CAP = 0;

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

    /** Chamadas que o IMGUR diz restarem à aplicação (último cabeçalho/credits). */
    private ?int $remoteRemaining = null;

    /**
     * O imgur recusou a credencial de vez (403 `Invalid client_id`). Diferente
     * da cota: não zera à meia-noite — uma chave errada continua errada.
     */
    private bool $invalid = false;

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
        if ($this->invalid || $this->remoteExhausted) {
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

    public function remoteRemaining(): ?int
    {
        return $this->remoteRemaining;
    }

    /** O imgur recusou a credencial (403 em `/3/credits`)? */
    public function invalid(): bool
    {
        return $this->invalid;
    }

    /** Declara a credencial recusada: a partir daqui nada mais é consultado. */
    public function markInvalid(): void
    {
        $this->invalid = true;
    }

    /**
     * O imgur disse que a cota da APLICAÇÃO acabou. Também é o que ele responde
     * a um Client-ID desconhecido em `/3/image/<id>` — por isso o pré-voo em
     * `/3/credits` existe.
     */
    public function markRemoteExhausted(?int $reset = null): void
    {
        $this->remoteExhausted = true;
        $this->remoteRemaining = 0;
        $this->remoteReset = $reset;
    }

    public function creditsEndpoint(): string
    {
        return self::CREDITS;
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
        $remaining = self::digits($headers['x-ratelimit-clientremaining'] ?? null);
        if ($remaining === null) {
            return;
        }

        $this->remoteRemaining = $remaining;

        if ($remaining <= 0) {
            $this->markRemoteExhausted(self::digits($headers['x-ratelimit-clientreset'] ?? null));
        }
    }

    /**
     * Um 429 acompanhado de `X-RateLimit-ClientRemaining: 0` é a cota da
     * APLICAÇÃO (ou uma credencial desconhecida) — nada que backoff resolva
     * dentro deste run. Quem retenta usa isto para parar na primeira.
     *
     * @param array<string, string> $headers nomes em minúsculas
     */
    public static function clientQuotaGone(array $headers): bool
    {
        $remaining = self::digits($headers['x-ratelimit-clientremaining'] ?? null);

        return $remaining !== null && $remaining <= 0;
    }

    /**
     * Interpreta a resposta de `/3/credits` (o pré-voo).
     *
     *  - ok: credencial aceita; `remaining`/`limit` são o que o imgur diz da
     *    cota da aplicação HOJE (independente do nosso contador).
     *  - invalid: 401/403 — o imgur não conhece este Client-ID.
     *  - nem um nem outro: resposta inesperada (rede, 5xx, 429); quem chama
     *    decide se segue sem a verificação.
     *
     * @return array{ok: bool, invalid: bool, remaining: ?int, limit: ?int, error: ?string}
     */
    public function parseCredits(int $status, string $body): array
    {
        $json = json_decode($body, true);
        $data = is_array($json) ? ($json['data'] ?? null) : null;
        $message = is_array($data) && isset($data['error'])
            ? (is_string($data['error']) ? $data['error'] : json_encode($data['error']))
            : null;

        if ($status === 401 || $status === 403) {
            return [
                'ok'        => false,
                'invalid'   => true,
                'remaining' => null,
                'limit'     => null,
                'error'     => 'imgur API: Client-ID recusado (HTTP ' . $status . ($message ? ', ' . $message : '') . ')',
            ];
        }

        if ($status === 200 && is_array($json) && ($json['success'] ?? false) === true && is_array($data)) {
            $remaining = self::digits($data['ClientRemaining'] ?? null);
            $limit = self::digits($data['ClientLimit'] ?? null);

            if ($remaining !== null) {
                $this->remoteRemaining = $remaining;
                if ($remaining <= 0) {
                    $this->markRemoteExhausted($this->remoteReset);
                }
            }

            return ['ok' => true, 'invalid' => false, 'remaining' => $remaining, 'limit' => $limit, 'error' => null];
        }

        return [
            'ok'        => false,
            'invalid'   => false,
            'remaining' => null,
            'limit'     => null,
            'error'     => 'imgur API: resposta inesperada em /3/credits (HTTP ' . $status . ($message ? ', ' . $message : '') . ')',
        ];
    }

    /** Inteiro não negativo de um cabeçalho/campo, ou null quando não é número. */
    private static function digits(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return max(0, $raw);
        }
        if (is_float($raw)) {
            return max(0, (int) $raw);
        }

        $raw = is_string($raw) ? trim($raw) : '';

        return $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
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
            $this->remoteRemaining = null;
            // `invalid` fica: uma chave recusada não passa a valer amanhã.
        }
    }

    private function persist(): void
    {
        if ($this->save !== null) {
            ($this->save)($this->state());
        }
    }
}
