<?php

namespace Ramon\MybbMigrator\Support;

/**
 * Baixa uma imagem remota com as salvaguardas que um fórum antigo exige:
 *
 *  - segue redirects, mas VALIDA o destino final: se a resposta for HTML, o
 *    arquivo morreu. É o caso relatado do imgur — `i.imgur.com/T1Ji3QD.jpg`
 *    redireciona para a página `imgur.com/T1Ji3QD` quando a extensão pedida não
 *    bate com o formato realmente armazenado. Nesse caso tentamos as extensões
 *    alternativas (.png/.jpeg/.gif/.webp) do mesmo id antes de desistir — ver
 *    candidates().
 *  - reconhece o placeholder `removed.png` do imgur (imagem apagada) e trata
 *    como falha, em vez de gravar um PNG cinza de "imagem removida".
 *  - teto de bytes por arquivo, abortando no meio do download (não depois).
 *  - detecta o MIME pelo CONTEÚDO (finfo/magic bytes), nunca pela extensão ou
 *    pelo Content-Type declarado.
 *
 * E, principalmente, as duas defesas contra os erros que uma migração real
 * produz em massa:
 *
 *  1. HTTP 429 (imgur). Um run varre centenas de URLs do MESMO host em poucos
 *     segundos e leva rate limit em bloco. Cada host tem agora um INTERVALO
 *     MÍNIMO entre requisições e uma PENALIDADE que dobra a cada 429 (e cai
 *     pela metade a cada sucesso), além de retentativa com backoff que respeita
 *     o cabeçalho `Retry-After`. Uma falha 429 também não gasta mais as
 *     variantes de extensão do imgur — seria multiplicar por 5 o tráfego que já
 *     está sendo recusado.
 *  2. Timeout no meio do download (postimg.cc). O `CURLOPT_TIMEOUT` cru mata
 *     downloads que estão progredindo, só que devagar ("timed out ... with
 *     109854 out of 295736 bytes received"). O teto passou a ser de OCIOSIDADE
 *     (low speed), com um teto absoluto muito maior; e, se ainda assim cair, a
 *     retentativa RETOMA por `Range:` a partir dos bytes já recebidos.
 *
 * Falhas transitórias (429/5xx/timeout) voltam marcadas com `transient => true`
 * para que quem chama NÃO as registre como "imagem morta" — elas merecem outra
 * execução, ao contrário de um 404.
 */
final class ImageFetcher
{
    private const UA = 'Mozilla/5.0 (compatible; MybbMigrator/1.0; +flarum)';

    /** Extensões tentadas quando o imgur devolve HTML para a URL original. */
    private const IMGUR_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** MIMEs aceitos como imagem final, e a extensão que gravamos. */
    private const IMAGE_MIMES = [
        'image/jpeg'    => 'jpg',
        'image/pjpeg'   => 'jpg',
        'image/png'     => 'png',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'image/bmp'     => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/avif'    => 'avif',
        'image/svg+xml' => 'svg',
    ];

    /** Status HTTP que valem outra tentativa (o arquivo provavelmente existe). */
    private const RETRIABLE_STATUS = [408, 425, 429, 500, 502, 503, 504, 509, 520, 521, 522, 523, 524];

    /**
     * Erros de cURL que valem outra tentativa. Números, e não as constantes
     * CURLE_*, porque a classe é carregada nos testes sem a extensão curl.
     *
     * 6 resolve host, 7 connect, 16 HTTP/2, 18 arquivo parcial, 28 timeout,
     * 35 handshake TLS, 52 resposta vazia, 55 send, 56 recv, 92 stream HTTP/2.
     */
    private const RETRIABLE_CURL = [6, 7, 16, 18, 28, 35, 52, 55, 56, 92];

    /** Teto da penalidade por host: acima disso o run inteiro pararia de andar. */
    private const PENALTY_CAP = 30.0;

    /** Intervalo mínimo entre requisições ao mesmo host, em segundos. */
    private float $hostDelay;

    /** @var array<string, float> host => instante (microtime) do próximo slot livre */
    private array $hostNextAt = [];

    /** @var array<string, float> host => penalidade em segundos (dobra a cada 429) */
    private array $hostPenalty = [];

    public function __construct(
        private int $timeout = 20,
        private int $maxBytes = 10485760,
        private int $retries = 3,
        int $hostDelayMs = 250,
    ) {
        $this->hostDelay = max(0, $hostDelayMs) / 1000;
    }

    public static function extensionFor(?string $mime): ?string
    {
        return $mime === null ? null : (self::IMAGE_MIMES[strtolower($mime)] ?? null);
    }

    /**
     * Tenta a URL original e, se ela devolver HTML (imagem morta / página do
     * imgur), as variantes de extensão do mesmo id. Devolve o primeiro sucesso
     * ou o último erro.
     *
     * @return array{ok: bool, bytes: ?string, mime: ?string, ext: ?string, final_url: ?string, error: ?string, transient: bool}
     */
    public function fetchImage(string $url): array
    {
        $last = null;

        foreach ($this->candidates($url) as $candidate) {
            $res = $this->get($candidate);

            if (! $res['ok']) {
                // Rate limit / timeout / 5xx: as variantes de extensão do imgur
                // apontam para o MESMO objeto no MESMO host — insistir nelas só
                // multiplicaria o tráfego que já está sendo recusado.
                if ($res['transient'] ?? false) {
                    return $this->clean($res);
                }

                $last = $res;
                continue;
            }

            $mime = $this->sniff((string) $res['bytes']);
            $ext = self::extensionFor($mime);

            if ($ext === null) {
                // Não é imagem: quase sempre a página HTML de "imagem removida".
                $last = $this->err(
                    $this->looksLikeHtml((string) $res['bytes'])
                        ? 'destino devolveu HTML (imagem removida/expirada)'
                        : 'tipo não suportado: ' . ($mime ?? 'desconhecido'),
                    $res['final_url']
                );
                continue;
            }

            if ($this->isImgurPlaceholder((string) ($res['final_url'] ?? $candidate))) {
                $last = $this->err('imgur: imagem removida (removed.png)', $res['final_url']);
                continue;
            }

            return [
                'ok'        => true,
                'bytes'     => $res['bytes'],
                'mime'      => $mime,
                'ext'       => $ext,
                'final_url' => $res['final_url'],
                'error'     => null,
                'transient' => false,
            ];
        }

        return $this->clean($last ?? $this->err('nenhum candidato de URL'));
    }

    /**
     * Baixa um arquivo qualquer (anexo do MyBB), sem exigir que seja imagem.
     *
     * @return array{ok: bool, bytes: ?string, mime: ?string, ext: ?string, final_url: ?string, error: ?string, transient: bool}
     */
    public function fetchFile(string $url): array
    {
        $res = $this->get($url);
        if (! $res['ok']) {
            return $this->clean($res);
        }

        if ($this->looksLikeHtml((string) $res['bytes'])) {
            return $this->clean($this->err('destino devolveu HTML (login exigido ou arquivo ausente)', $res['final_url']));
        }

        $mime = $this->sniff((string) $res['bytes']);

        return [
            'ok'        => true,
            'bytes'     => $res['bytes'],
            'mime'      => $mime,
            'ext'       => self::extensionFor($mime),
            'final_url' => $res['final_url'],
            'error'     => null,
            'transient' => false,
        ];
    }

    /**
     * URLs a tentar, em ordem. No imgur a página `imgur.com/<id>` e as variantes
     * `i.imgur.com/<id>.<ext>` apontam para o mesmo objeto — a extensão na URL é
     * só um pedido de conversão. Quando ela não bate, o imgur redireciona para a
     * página HTML; então geramos as variantes do mesmo id.
     *
     * @return array<int, string>
     */
    public function candidates(string $url): array
    {
        $out = [$url];

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($host !== 'imgur.com' && ! str_ends_with($host, '.imgur.com')) {
            return $out;
        }

        // /a/xxxx (álbum), /gallery/xxxx e /t/... não são imagens diretas.
        if (preg_match('#^/(a|gallery|t)/#i', $path)) {
            return $out;
        }

        $id = pathinfo($path, PATHINFO_FILENAME);
        if ($id === '' || ! preg_match('/^[A-Za-z0-9]{5,15}$/', $id)) {
            return $out;
        }

        foreach (self::IMGUR_EXTS as $ext) {
            $guess = 'https://i.imgur.com/' . $id . '.' . $ext;
            if (! in_array($guess, $out, true)) {
                $out[] = $guess;
            }
        }

        return $out;
    }

    /**
     * O status HTTP é transitório (vale outra execução) ou definitivo (imagem
     * morta)? Público porque quem chama precisa da mesma resposta para decidir
     * se grava a URL como `failed` para sempre.
     */
    public static function isTransientStatus(int $status): bool
    {
        return in_array($status, self::RETRIABLE_STATUS, true);
    }

    /**
     * GET com teto de bytes, intervalo por host e retentativas. Usa cURL quando
     * disponível (redirects, abort no meio do download, retomada por Range);
     * cai para stream wrapper caso contrário.
     *
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        if (! preg_match('#^https?://#i', $url)) {
            return $this->err('URL sem esquema http(s)');
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $useCurl = function_exists('curl_init');
        $carry = '';

        for ($attempt = 0; ; $attempt++) {
            $this->awaitSlot($host);

            $res = $useCurl ? $this->getCurl($url, $carry) : $this->getStream($url);

            if ($res['ok']) {
                $this->reward($host);

                return $res;
            }

            if (! ($res['transient'] ?? false) || $attempt >= $this->retries) {
                return $res;
            }

            // O que já chegou não se perde: a próxima tentativa pede o RESTO.
            $partial = (string) ($res['partial'] ?? '');
            if ($useCurl && strlen($partial) > strlen($carry)) {
                $carry = $partial;
            }

            $this->sleep($this->penalize($host, $res, $attempt));
        }
    }

    /**
     * @param string $carry bytes já recebidos numa tentativa anterior; quando
     *                      não vazio a requisição pede só o restante (Range).
     * @return array<string, mixed>
     */
    private function getCurl(string $url, string $carry = ''): array
    {
        $ch = curl_init();
        $body = '';
        $tooBig = false;
        $max = $this->maxBytes;
        $offset = strlen($carry);
        $retryAfter = null;
        $headerStatus = 0;

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            // Teto de OCIOSIDADE, não de duração: um download lento porém vivo
            // (postimg.cc em horário ruim) chega ao fim; um travado morre em
            // $timeout segundos sem tráfego.
            CURLOPT_LOW_SPEED_LIMIT => 512,
            CURLOPT_LOW_SPEED_TIME  => $this->timeout,
            // Teto absoluto, só para nada ficar pendurado para sempre.
            CURLOPT_TIMEOUT        => max(120, $this->timeout * 6),
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => ['Accept: image/avif,image/webp,image/*,*/*;q=0.8'],
            // Fóruns antigos e CDNs de imagem frequentemente têm cadeia de
            // certificados quebrada; o conteúdo é validado por magic bytes.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HEADER         => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => function ($_ch, string $line) use (&$retryAfter, &$headerStatus): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $headerStatus = (int) $m[1];
                    $retryAfter = null; // novo bloco de cabeçalhos (redirect)
                } elseif (preg_match('#^Retry-After:\s*(.+)$#i', $line, $m)) {
                    $retryAfter = $this->parseRetryAfter(trim($m[1]));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($_ch, string $chunk) use (&$body, &$tooBig, $max, $offset): int {
                $body .= $chunk;
                if ($offset + strlen($body) > $max) {
                    $tooBig = true;

                    return -1; // aborta o download
                }

                return strlen($chunk);
            },
        ]);

        if ($offset > 0) {
            curl_setopt($ch, CURLOPT_RANGE, $offset . '-');
        }

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        // sem curl_close(): handles de cURL são objetos desde o PHP 8.0 e a
        // função virou deprecada no 8.5 — o recurso é liberado sozinho.

        // 206 = o servidor honrou o Range e mandou só o resto; 200 = ignorou o
        // pedido e recomeçou do zero (aí o que já tínhamos é lixo).
        $resumed = $offset > 0 && ($status === 206 || $headerStatus === 206);
        $full = $resumed ? $carry . $body : $body;

        if ($tooBig) {
            return $this->err('excede o limite de ' . $this->mb($this->maxBytes) . ' MB', $final);
        }
        if ($errno !== 0) {
            return $this->err('curl: ' . $error, $final, in_array($errno, self::RETRIABLE_CURL, true), 0, null, $full);
        }
        if ($status < 200 || $status >= 300) {
            return $this->err(
                'HTTP ' . $status . ($status === 429 ? ' (limite de requisições do host)' : ''),
                $final,
                self::isTransientStatus($status),
                $status,
                $retryAfter
            );
        }
        if ($full === '') {
            return $this->err('resposta vazia', $final, true);
        }

        return ['ok' => true, 'bytes' => $full, 'mime' => null, 'ext' => null, 'final_url' => $final, 'error' => null, 'transient' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function getStream(string $url): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => $this->timeout,
                'follow_location' => 1,
                'max_redirects'   => 6,
                'ignore_errors'   => true,
                'header'          => 'User-Agent: ' . self::UA . "\r\n"
                    . "Accept: image/avif,image/webp,image/*,*/*;q=0.8\r\n",
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $handle = @fopen($url, 'rb', false, $ctx);
        if ($handle === false) {
            return $this->err('não foi possível abrir a URL', null, true);
        }

        $meta = stream_get_meta_data($handle);
        $status = 0;
        $final = $url;
        $retryAfter = null;
        foreach ((array) ($meta['wrapper_data'] ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                $status = (int) $m[1];
                $retryAfter = null;
            } elseif (preg_match('#^Location:\s*(.+)$#i', (string) $line, $m)) {
                $final = trim($m[1]);
            } elseif (preg_match('#^Retry-After:\s*(.+)$#i', (string) $line, $m)) {
                $retryAfter = $this->parseRetryAfter(trim($m[1]));
            }
        }

        $body = (string) stream_get_contents($handle, $this->maxBytes + 1);
        fclose($handle);

        if (strlen($body) > $this->maxBytes) {
            return $this->err('excede o limite de ' . $this->mb($this->maxBytes) . ' MB', $final);
        }
        if ($status !== 0 && ($status < 200 || $status >= 300)) {
            return $this->err('HTTP ' . $status, $final, self::isTransientStatus($status), $status, $retryAfter);
        }
        if ($body === '') {
            return $this->err('resposta vazia', $final, true);
        }

        return ['ok' => true, 'bytes' => $body, 'mime' => null, 'ext' => null, 'final_url' => $final, 'error' => null, 'transient' => false];
    }

    /**
     * Segura a requisição até o host poder receber outra: intervalo mínimo +
     * penalidade acumulada. É isto que evita disparar 200 GETs no i.imgur.com em
     * três segundos e levar 429 em todos.
     */
    private function awaitSlot(string $host): void
    {
        if ($host === '') {
            return;
        }

        $now = microtime(true);
        $next = $this->hostNextAt[$host] ?? 0.0;

        if ($next > $now) {
            $this->sleep($next - $now);
            $now = microtime(true);
        }

        $this->hostNextAt[$host] = $now + $this->hostDelay + ($this->hostPenalty[$host] ?? 0.0);
    }

    /**
     * Aumenta a penalidade do host e devolve quanto esperar antes de repetir.
     *
     * @param array<string, mixed> $res
     */
    private function penalize(string $host, array $res, int $attempt): float
    {
        $status = (int) ($res['status'] ?? 0);
        $isRateLimit = $status === 429 || $status === 503 || $status === 509;

        if ($isRateLimit) {
            // Dobra a cada recusa: o host INTEIRO passa a ser tratado devagar
            // pelo resto do run, não só esta URL.
            $this->hostPenalty[$host] = min(self::PENALTY_CAP, max(1.0, ($this->hostPenalty[$host] ?? 0.0) * 2));
        } else {
            // Timeout/erro de rede: o host não está recusando, está lento.
            $this->hostPenalty[$host] = min(self::PENALTY_CAP, ($this->hostPenalty[$host] ?? 0.0) + 0.25);
        }

        $retryAfter = $res['retry_after'] ?? null;
        if (is_int($retryAfter) && $retryAfter > 0) {
            return (float) min($retryAfter, 120);
        }

        // Backoff exponencial com jitter (sem ele, 50 URLs do mesmo host voltam
        // todas no mesmo instante e levam 429 juntas de novo).
        $base = $isRateLimit ? 2.0 : 1.0;

        return min(60.0, $base * (2 ** $attempt)) + (random_int(0, 400) / 1000);
    }

    /** Um sucesso alivia o host: a penalidade cai pela metade. */
    private function reward(string $host): void
    {
        if (! isset($this->hostPenalty[$host])) {
            return;
        }

        $this->hostPenalty[$host] /= 2;
        if ($this->hostPenalty[$host] < 0.1) {
            unset($this->hostPenalty[$host]);
        }
    }

    private function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1000000));
        }
    }

    /** `Retry-After` vem em segundos ou como data HTTP. */
    private function parseRetryAfter(string $raw): ?int
    {
        if ($raw === '') {
            return null;
        }
        if (ctype_digit($raw)) {
            return (int) $raw;
        }

        $when = strtotime($raw);

        return $when === false ? null : max(0, $when - time());
    }

    private function sniff(string $bytes): ?string
    {
        if (function_exists('finfo_buffer')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = @finfo_buffer($finfo, $bytes);
                // idem finfo_close(): objeto liberado automaticamente
                if (is_string($mime) && $mime !== '' && $mime !== 'application/octet-stream') {
                    return strtolower($mime);
                }
            }
        }

        // Fallback por magic bytes (finfo pode estar desabilitado).
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF")      => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($bytes, 'GIF87a'),
            str_starts_with($bytes, 'GIF89a')            => 'image/gif',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($bytes, 'BM')                => 'image/bmp',
            default                                      => null,
        };
    }

    private function looksLikeHtml(string $bytes): bool
    {
        $head = strtolower(substr(ltrim($bytes), 0, 200));

        return str_contains($head, '<!doctype html')
            || str_contains($head, '<html')
            || str_contains($head, '<head');
    }

    private function isImgurPlaceholder(string $url): bool
    {
        return (bool) preg_match('#imgur\.com/removed\.(png|jpe?g|gif)#i', $url);
    }

    private function mb(int $bytes): string
    {
        return (string) round($bytes / 1048576, 1);
    }

    /**
     * Tira do resultado as chaves internas (bytes parciais, status) antes de
     * devolvê-lo a quem chamou.
     *
     * @param array<string, mixed> $res
     * @return array{ok: bool, bytes: ?string, mime: ?string, ext: ?string, final_url: ?string, error: ?string, transient: bool}
     */
    private function clean(array $res): array
    {
        return [
            'ok'        => (bool) $res['ok'],
            'bytes'     => $res['bytes'] ?? null,
            'mime'      => $res['mime'] ?? null,
            'ext'       => $res['ext'] ?? null,
            'final_url' => $res['final_url'] ?? null,
            'error'     => $res['error'] ?? null,
            'transient' => (bool) ($res['transient'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function err(
        string $message,
        ?string $final = null,
        bool $transient = false,
        int $status = 0,
        ?int $retryAfter = null,
        string $partial = '',
    ): array {
        return [
            'ok'          => false,
            'bytes'       => null,
            'mime'        => null,
            'ext'         => null,
            'final_url'   => $final,
            'error'       => $message,
            'transient'   => $transient,
            'status'      => $status,
            'retry_after' => $retryAfter,
            'partial'     => $partial,
        ];
    }
}
