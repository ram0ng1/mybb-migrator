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
 *
 * E, quando o admin configura mais de um IP de saída (ver {@see ExitPool}), o
 * ritmo deixa de ser por host e passa a ser por HOST + IP: a cota de 429 do
 * imgur é por endereço de origem, então dois IPs são duas cotas e o run anda em
 * dobro sem apertar nenhuma delas. A escolha do IP a cada requisição é a do que
 * estiver livre mais cedo NAQUELE host, e uma retentativa depois de 429 nunca
 * sai pelo endereço que acabou de ser recusado.
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

    /**
     * Erros de cURL que acusam o EXIT, e não a URL: interface local que não
     * existe (45), proxy que não resolve (5) ou que recusa conexão (7). São
     * eles que tiram um IP do rodízio — um 429, ao contrário, PROVA que o IP
     * está vivo.
     */
    private const EXIT_FAULT_CURL = [5, 7, 45];

    /** Teto da penalidade por host: acima disso o run inteiro pararia de andar. */
    private const PENALTY_CAP = 30.0;

    /** Intervalo mínimo entre requisições ao mesmo host, em segundos. */
    private float $hostDelay;

    /**
     * O ritmo é contado por HOST **e** por EXIT, não só por host — e é isto que
     * faz a rotação de IP valer alguma coisa. A cota de 429 do imgur é por IP
     * de origem; se os dois IPs dividissem o mesmo contador, rotacionar só
     * trocaria de endereço sem ganhar vazão nenhuma.
     *
     * @var array<string, float> "host|exit" => instante (microtime) do próximo slot livre
     */
    private array $hostNextAt = [];

    /** @var array<string, float> "host|exit" => penalidade em segundos (dobra a cada 429) */
    private array $hostPenalty = [];

    /** Por onde as requisições saem. Sem configuração, um exit "direto". */
    private ExitPool $exits;

    /** Ponteiro do rodízio, para desempatar exits igualmente livres. */
    private int $cursor = 0;

    /**
     * Avisado a cada retentativa, para que ela apareça no console em vez de o
     * run simplesmente parar de andar por meio minuto sem explicação.
     *
     * @var null|callable(array<string, mixed>): void
     */
    private $onRetry = null;

    /**
     * Avisado quando um IP de saída sai do rodízio de vez. Merece linha própria:
     * é erro de CONFIGURAÇÃO (IP não bound, proxy morto), não percalço de rede.
     *
     * @var null|callable(array<string, mixed>): void
     */
    private $onExitDown = null;

    public function __construct(
        private int $timeout = 20,
        private int $maxBytes = 10485760,
        private int $retries = 3,
        int $hostDelayMs = 250,
        ?ExitPool $exits = null,
    ) {
        $this->hostDelay = max(0, $hostDelayMs) / 1000;
        $this->exits = $exits ?? ExitPool::direct();
    }

    public function exits(): ExitPool
    {
        return $this->exits;
    }

    /**
     * Registra quem observa as retentativas. O fetcher não conhece console nem
     * tradutor — ele descreve o que aconteceu (url, host, tentativa, espera,
     * erro) e quem chamou decide como mostrar.
     *
     * @param null|callable(array<string, mixed>): void $listener
     */
    public function onRetry(?callable $listener): self
    {
        $this->onRetry = $listener;

        return $this;
    }

    /**
     * @param null|callable(array<string, mixed>): void $listener
     */
    public function onExitDown(?callable $listener): self
    {
        $this->onExitDown = $listener;

        return $this;
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
        $avoid = null;

        for ($attempt = 0; ; $attempt++) {
            $exit = $this->pickExit($host, $avoid);

            if ($exit === null) {
                // Transitório de propósito: as imagens ficam `deferred` e voltam
                // no próximo run. Cair no IP direto por conta própria seria
                // ignorar em silêncio a única coisa que o admin pediu aqui.
                return $this->err('nenhum IP de saída disponível (todos falharam ao conectar)', null, true);
            }

            $this->awaitSlot($host, $exit);

            $res = $useCurl
                ? $this->getCurl($url, $carry, $exit)
                : $this->getStream($url, $exit);

            if ($res['ok']) {
                $this->reward($host, $exit);

                return $res;
            }

            // Falha de CONEXÃO acusa o exit; 429/5xx acusam a URL ou o host.
            if (in_array((int) ($res['errno'] ?? 0), self::EXIT_FAULT_CURL, true)
                && $this->exits->strike($exit['key'])
                && $this->onExitDown !== null) {
                ($this->onExitDown)(['exit' => $exit['label'], 'error' => (string) ($res['error'] ?? '')]);
            }

            if (! ($res['transient'] ?? false) || $attempt >= $this->retries) {
                return $res;
            }

            // O que já chegou não se perde: a próxima tentativa pede o RESTO.
            // Só que o Range só vale no MESMO exit — um proxy diferente não
            // continua o download que o outro começou.
            $partial = (string) ($res['partial'] ?? '');
            if ($useCurl && strlen($partial) > strlen($carry)) {
                $carry = $partial;
            }

            $wait = $this->penalize($host, $exit, $res, $attempt);

            // Levou 429 (ou o exit caiu)? A próxima tentativa sai por outro IP —
            // insistir no endereço que acabou de ser recusado é esperar à toa.
            $status = (int) ($res['status'] ?? 0);
            if ($status === 429 || $status === 503 || $status === 509 || (int) ($res['errno'] ?? 0) !== 0) {
                $avoid = $exit['key'];
                $carry = '';
            } else {
                $avoid = null;
            }

            if ($this->onRetry !== null) {
                ($this->onRetry)([
                    'url'     => $url,
                    'host'    => $host,
                    'exit'    => $exit['label'],
                    'attempt' => $attempt + 1,
                    'of'      => $this->retries,
                    'wait'    => number_format($wait, 1, '.', ''),
                    'error'   => (string) ($res['error'] ?? ''),
                ]);
            }

            $this->sleep($wait);
        }
    }

    /**
     * Qual IP usar agora. Vence o que estiver livre mais cedo NESTE host: um
     * endereço que acabou de levar 429 no imgur carrega a penalidade dele e
     * naturalmente fica de fora enquanto os outros trabalham. Empate entre
     * exits igualmente livres cai no rodízio, para não viciar sempre no
     * primeiro da lista.
     *
     * @param ?string $avoid exit a evitar (o que acabou de ser recusado)
     * @return null|array{key: string, label: string, kind: string, value: string}
     */
    private function pickExit(string $host, ?string $avoid = null): ?array
    {
        $live = $this->exits->live();
        $total = count($live);

        if ($total === 0) {
            return null;
        }
        if ($total === 1) {
            return $live[0];
        }

        $best = null;
        $bestAt = null;

        for ($i = 0; $i < $total; $i++) {
            $exit = $live[($this->cursor + $i) % $total];

            if ($exit['key'] === $avoid) {
                continue;
            }

            $at = $this->hostNextAt[$this->slot($host, $exit)] ?? 0.0;
            if ($bestAt === null || $at < $bestAt) {
                $bestAt = $at;
                $best = $exit;
            }
        }

        $this->cursor++;

        // Só sobrou o exit que queríamos evitar: melhor ele do que desistir.
        return $best ?? $live[0];
    }

    /**
     * @param array<string, string> $exit
     */
    private function slot(string $host, array $exit): string
    {
        return $host . '|' . $exit['key'];
    }

    /**
     * @param string $carry bytes já recebidos numa tentativa anterior; quando
     *                      não vazio a requisição pede só o restante (Range).
     * @return array<string, mixed>
     */
    private function getCurl(string $url, string $carry = '', ?array $exit = null): array
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

        // A rotação de IP se resume a estas duas linhas: ou amarramos o
        // endereço de ORIGEM da conexão, ou mandamos tudo por um proxy.
        if (($exit['kind'] ?? 'direct') === 'interface') {
            curl_setopt($ch, CURLOPT_INTERFACE, $exit['value']);
        } elseif (($exit['kind'] ?? 'direct') === 'proxy') {
            curl_setopt($ch, CURLOPT_PROXY, $exit['value']);
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
            $retriable = in_array($errno, self::RETRIABLE_CURL, true)
                || in_array($errno, self::EXIT_FAULT_CURL, true);

            return $this->err('curl: ' . $error, $final, $retriable, 0, null, $full, $errno);
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
    private function getStream(string $url, ?array $exit = null): array
    {
        $http = [
            'method'          => 'GET',
            'timeout'         => $this->timeout,
            'follow_location' => 1,
            'max_redirects'   => 6,
            'ignore_errors'   => true,
            'header'          => 'User-Agent: ' . self::UA . "\r\n"
                . "Accept: image/avif,image/webp,image/*,*/*;q=0.8\r\n",
        ];
        $socket = [];

        $kind = $exit['kind'] ?? 'direct';

        if ($kind === 'interface') {
            // ':0' = qualquer porta de origem; o que estamos fixando é o IP.
            $socket['bindto'] = (string) $exit['value'] . ':0';
        } elseif ($kind === 'proxy') {
            // O wrapper http só fala com proxy HTTP, via tcp://. SOCKS ele não
            // sabe fazer — e ignorar isso em silêncio mandaria a requisição
            // pelo IP do servidor, exatamente o que a configuração proíbe.
            if (! str_starts_with((string) $exit['value'], 'http')) {
                return $this->err('proxy SOCKS exige a extensão curl', null, false);
            }

            $http['proxy'] = (string) preg_replace('#^https?://#i', 'tcp://', (string) $exit['value']);
            $http['request_fulluri'] = true;
        }

        $ctx = stream_context_create([
            'http'   => $http,
            'socket' => $socket,
            'ssl'    => ['verify_peer' => false, 'verify_peer_name' => false],
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
    private function awaitSlot(string $host, array $exit): void
    {
        if ($host === '') {
            return;
        }

        $slot = $this->slot($host, $exit);
        $now = microtime(true);
        $next = $this->hostNextAt[$slot] ?? 0.0;

        if ($next > $now) {
            $this->sleep($next - $now);
            $now = microtime(true);
        }

        $this->hostNextAt[$slot] = $now + $this->hostDelay + ($this->hostPenalty[$slot] ?? 0.0);
    }

    /**
     * Aumenta a penalidade do host e devolve quanto esperar antes de repetir.
     *
     * @param array<string, mixed> $res
     */
    private function penalize(string $host, array $exit, array $res, int $attempt): float
    {
        $slot = $this->slot($host, $exit);
        $status = (int) ($res['status'] ?? 0);
        $isRateLimit = $status === 429 || $status === 503 || $status === 509;

        if ($isRateLimit) {
            // Dobra a cada recusa: ESTE IP passa a tratar ESTE host devagar pelo
            // resto do run. Os outros IPs do rodízio seguem no ritmo normal —
            // a cota que estourou é a deles, não a do run inteiro.
            $this->hostPenalty[$slot] = min(self::PENALTY_CAP, max(1.0, ($this->hostPenalty[$slot] ?? 0.0) * 2));
        } else {
            // Timeout/erro de rede: o host não está recusando, está lento.
            $this->hostPenalty[$slot] = min(self::PENALTY_CAP, ($this->hostPenalty[$slot] ?? 0.0) + 0.25);
        }

        $retryAfter = $res['retry_after'] ?? null;
        if (is_int($retryAfter) && $retryAfter > 0) {
            // O host disse QUANDO voltar; obedecemos. Mas com um respingo de
            // jitter: dois processos que levaram o mesmo `Retry-After: 5` não
            // podem acordar no mesmo milissegundo e refazer a rajada juntos.
            return min((float) $retryAfter, 120.0) + self::jitter(1.0);
        }

        return self::backoffDelay($isRateLimit ? 2.0 : 1.0, $attempt);
    }

    /**
     * Backoff exponencial com "equal jitter": metade do intervalo é fixa,
     * metade é sorteada.
     *
     *     espera = t/2 + random(0, t/2),   t = min(cap, base * 2^tentativa)
     *
     * Por que não backoff seco (`t`, como era aqui antes): sem sorteio, tudo o
     * que falhou junto volta junto. Basta um segundo processo no mesmo host — a
     * migração de anexos rodando ao lado, ou o run disparado pelo painel — para
     * as duas filas entrarem em lockstep e dobrarem a rajada a cada rodada. E,
     * mesmo com um processo só, um run alinhado à janela fixa do imgur
     * reencontra o limite sempre na mesma fase.
     *
     * Por que não jitter puro (`random(0, t)`, a forma mais citada): ele pode
     * devolver ~0 e mandar outra requisição ao host que ACABOU de responder
     * 429. A metade fixa garante que cada recusa custe um mínimo crescente.
     */
    public static function backoffDelay(float $base, int $attempt, float $cap = 60.0): float
    {
        $window = min($cap, $base * (2 ** max(0, $attempt)));

        return $window / 2 + self::jitter($window / 2);
    }

    /** Sorteio uniforme em [0, $max] segundos, com resolução de milissegundo. */
    private static function jitter(float $max): float
    {
        return $max <= 0 ? 0.0 : random_int(0, (int) round($max * 1000)) / 1000;
    }

    /** Um sucesso alivia o par host+IP: a penalidade cai pela metade. */
    private function reward(string $host, array $exit): void
    {
        $this->exits->reward($exit['key']);

        $slot = $this->slot($host, $exit);
        if (! isset($this->hostPenalty[$slot])) {
            return;
        }

        $this->hostPenalty[$slot] /= 2;
        if ($this->hostPenalty[$slot] < 0.1) {
            unset($this->hostPenalty[$slot]);
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
        int $errno = 0,
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
            'errno'       => $errno,
        ];
    }
}
