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
 *
 * Três acréscimos vindos de uma migração real (fórum de 2010+, milhares de
 * imagens em hosts que já morreram ou mudaram de endereço):
 *
 *  3. HOST INALCANÇÁVEL. `Could not resolve host: s20.postimg.org` não é um
 *     soluço: é um domínio que não existe mais, e ele aparece centenas de vezes
 *     seguidas. Retentar cada uma inline (6 tentativas com backoff) é meia hora
 *     parada sem baixar nada. Agora: (a) com {@see deferConnectionFailures()}
 *     ligado, uma falha de CONEXÃO (DNS, connect, TLS) volta na primeira vez
 *     marcada `defer => true`, para quem chama guardar a URL e voltar a ela no
 *     FIM do run, quando o resto já andou; (b) por host, duas falhas de conexão
 *     seguidas desarmam o host pelo resto do run — as URLs seguintes dele voltam
 *     na hora, sem rede, também como `defer`. Um sucesso rearma.
 *  4. HOST QUE MUDOU DE ESQUEMA. O postimg de 2016 servia em
 *     `s20.postimg.org/<id>/<arquivo>`; hoje o MESMO id vive em
 *     `i.postimg.cc/<id>/<arquivo>`. Para os hosts legados a URL reescrita é
 *     tentada ANTES da original — ver candidates().
 *  5. imgur AUTENTICADO. Com um {@see ImgurClient} configurado, a imagem é
 *     resolvida pela API (uma chamada, com cota diária) em vez de chutar cinco
 *     extensões em i.imgur.com; um 404 da API é falha DEFINITIVA e a cota
 *     esgotada é falha TRANSITÓRIA (deferred, volta no dia seguinte). A API não
 *     atende IPv6, então essas chamadas forçam IPv4 e evitam exits IPv6.
 *  6. ESPELHOS DE TERCEIROS. Quando um servidor de verdade RESPONDEU e a
 *     resposta não serviu (404/HTML/placeholder do imgur, ou um 429 que
 *     encerrou os candidatos diretos), a MESMA imagem é tentada por até três
 *     espelhos — DuckDuckGo (`external-content.duckduckgo.com`), o Wayback
 *     Machine (`web.archive.org`, o único capaz de servir uma imagem que o
 *     host original já apagou) e `serveproxy.com`. Cada um é um HOST
 *     diferente do original, então um 429 nosso contra o imgur não os
 *     atinge — e a ORDEM roda a cada imagem (ver mirrors()) para não
 *     concentrar tráfego sempre no mesmo espelho primeiro e dar A ELE um
 *     rate limit próprio. Uma falha de CONEXÃO pura (nenhum servidor
 *     respondeu) NÃO libera os espelhos — é o item 3 que cuida dela, e
 *     insistir ali reusaria o mesmo caminho de rede que acabou de falhar. O
 *     serveproxy fica de fora da rotação do imgur: ele recodifica tudo para
 *     AVIF, inclusive o placeholder `removed.png` (confirmado: 503 bytes de
 *     PNG viram 761 bytes de AVIF), sem nenhum indício no `final_url` de que
 *     é ele — por isso esse placeholder também é reconhecido pelo HASH dos
 *     bytes, não só pela URL final.
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

    /**
     * Erros de cURL de CONEXÃO pura — nenhum byte chegou, o host não atendeu:
     * 6 resolve host, 7 connect, 35 handshake TLS. São os que valem adiar para
     * o fim do run e os que contam para desarmar o host.
     */
    private const CONNECT_CURL = [6, 7, 35];

    /**
     * Falhas de conexão SEGUIDAS num host até ele ser desarmado pelo resto do
     * run. Duas, e não uma: um DNS que falhou uma vez pode ter sido o resolver
     * local; duas seguidas é o domínio que morreu.
     */
    private const HOST_TRIP = 2;

    /** Hosts do postimg que só existem em URLs antigas (o s\d+ era o shard). */
    private const POSTIMG_LEGACY = '#^(?:s\d+\.)?(?:postimg\.(?:org|cc|io)|postimage\.org)$#i';

    /** Onde o postimg serve hoje qualquer id antigo. */
    private const POSTIMG_MODERN = 'https://i.postimg.cc';

    /** Teto da penalidade por host: acima disso o run inteiro pararia de andar. */
    private const PENALTY_CAP = 30.0;

    /** Repassa os bytes exatamente como vieram — confirmado com o mesmo hash do fetch direto. */
    private const MIRROR_DUCKDUCKGO = ['tpl' => 'https://external-content.duckduckgo.com/iu/?u=%s', 'raw' => false];

    /**
     * O timestamp `20000000000000` não precisa bater com uma captura real: o
     * Wayback redireciona para a mais próxima que existir. `if_` pede o
     * conteúdo sem a barra de ferramentas dele.
     */
    private const MIRROR_WAYBACK = ['tpl' => 'https://web.archive.org/web/20000000000000if_/%s', 'raw' => true];

    /**
     * Recodifica TUDO para AVIF, inclusive o placeholder `removed.png` do
     * imgur — ver mirrors(). Só entra no rodízio fora do imgur.
     */
    private const MIRROR_SERVEPROXY = ['tpl' => 'https://serveproxy.com/?url=%s', 'raw' => false];

    /**
     * SHA-256 do placeholder `removed.png` do imgur: 503 bytes, sempre os
     * MESMOS (confirmado buscando a URL duas vezes). Pega o caso que o
     * `final_url` não pega: um espelho que devolve HTTP 200 com esses bytes
     * sem nunca expor o redirect original do imgur.
     */
    private const IMGUR_REMOVED_SHA256 = '9b5936f4006146e4e1e9025b474c02863c0b5614132ad40db4b925a10e8bfbb9';

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

    /** Ponteiro do rodízio dos espelhos externos — ver mirrors(). */
    private int $mirrorCursor = 0;

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

    /**
     * Avisos pontuais que merecem uma linha própria no console: host desarmado,
     * cota da API do imgur esgotada. Payload: ['kind' => ..., ...detalhes].
     *
     * @var null|callable(array<string, mixed>): void
     */
    private $onNotice = null;

    /** Acesso autenticado ao imgur; null = chutar extensões como sempre. */
    private ?ImgurClient $imgur = null;

    /**
     * Falha de conexão volta na PRIMEIRA vez (marcada `defer`) em vez de ser
     * retentada inline? Quem chama liga isto na varredura e desliga na passada
     * final sobre o que ficou pendente.
     */
    private bool $deferConnectionFailures = false;

    /** @var array<string, int> host => falhas de conexão seguidas */
    private array $hostFailures = [];

    /** @var array<string, true> host => desarmado pelo resto do run */
    private array $hostTripped = [];

    /** Cota da API do imgur já anunciada como esgotada neste run? */
    private bool $imgurCapAnnounced = false;

    public function __construct(
        private int $timeout = 20,
        private int $maxBytes = 10485760,
        private int $retries = 3,
        int $hostDelayMs = 250,
        ?ExitPool $exits = null,
        /**
         * Verificar o certificado TLS do host. Ligado por padrão: sem isso, quem
         * intercepta a rede durante a migração escolhe o que vai parar na pasta
         * de assets do fórum. CDNs antigas com cadeia quebrada falham com erro
         * claro (curl 60) e o admin decide se roda com `--insecure`.
         */
        private bool $verifyTls = true,
    ) {
        $this->hostDelay = max(0, $hostDelayMs) / 1000;
        $this->exits = $exits ?? ExitPool::direct();
    }

    public function verifiesTls(): bool
    {
        return $this->verifyTls;
    }

    /**
     * Pacote de CAs para o cURL quando o PHP não traz um (comum no Windows,
     * onde sem isto TODA conexão https falha com "unable to get local issuer").
     * O Flarum depende do composer/ca-bundle via Guzzle; se ele estiver
     * disponível, usamos.
     */
    private function caBundle(): ?string
    {
        if (! class_exists(\Composer\CaBundle\CaBundle::class)) {
            return null;
        }

        $path = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();

        return is_string($path) && is_file($path) ? $path : null;
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

    /**
     * @param null|callable(array<string, mixed>): void $listener
     */
    public function onNotice(?callable $listener): self
    {
        $this->onNotice = $listener;

        return $this;
    }

    /** Liga o imgur autenticado. Um cliente sem Client-ID é o mesmo que null. */
    public function withImgur(?ImgurClient $client): self
    {
        $this->imgur = $client !== null && $client->configured() ? $client : null;

        return $this;
    }

    public function imgur(): ?ImgurClient
    {
        return $this->imgur;
    }

    /**
     * Falhas de CONEXÃO (DNS, connect, TLS) voltam na primeira vez, marcadas
     * `defer => true`, em vez de gastar as retentativas inline. 429/timeout no
     * meio do download continuam sendo retentados na hora — esses o host está
     * respondendo, só devagar.
     */
    public function deferConnectionFailures(bool $on = true): self
    {
        $this->deferConnectionFailures = $on;

        return $this;
    }

    /**
     * Rearma todos os hosts desarmados. Chamado antes da passada final sobre as
     * URLs adiadas: cada host ganha mais {@see HOST_TRIP} chances — e, se cair
     * de novo, o restante dele volta a ser pulado na hora.
     */
    public function resetHosts(): self
    {
        $this->hostFailures = [];
        $this->hostTripped = [];

        return $this;
    }

    /** Hosts desarmados neste run (para o resumo). @return array<int, string> */
    public function trippedHosts(): array
    {
        return array_keys($this->hostTripped);
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
     * @return array{ok: bool, bytes: ?string, mime: ?string, ext: ?string, final_url: ?string, error: ?string, transient: bool, defer: bool}
     */
    public function fetchImage(string $url): array
    {
        $last = null;
        $candidates = $this->candidates($url);

        // Só vale gastar os espelhos quando ALGUÉM respondeu de verdade — um
        // status HTTP (mesmo que 404/429), ou bytes que vieram e foram
        // recusados a seguir. Falha de CONEXÃO pura (DNS, connect, exit/proxy
        // que não sobe) já tem o mecanismo dela — defer/host-trip — e tentar
        // os espelhos ali reusaria o MESMO caminho de rede que acabou de
        // falhar, sem nenhuma chance real.
        $sawServer = false;

        // imgur com credencial: UMA chamada à API resolve o link direto certo,
        // em vez de até cinco GETs chutando extensão. Só vale para o que é
        // imagem única (id reconhecível); álbuns/galerias seguem como sempre.
        if ($this->imgur !== null && ($imgurId = $this->imgurId($url)) !== null) {
            $api = $this->resolveImgur($imgurId);

            if ($api['ok']) {
                $candidates = [(string) $api['link']];
            } elseif ($api['final']) {
                if ((bool) ($api['res']['transient'] ?? false)) {
                    // Cota esgotada ou credencial recusada: nada a ganhar
                    // tentando por qualquer caminho agora — é exatamente o
                    // tráfego que o teto existe para evitar. Adiada, volta no
                    // próximo run.
                    return $this->clean($api['res']);
                }

                // A API confirmou que a imagem foi apagada (404/400): os
                // palpites de extensão em i.imgur.com não têm mais o que
                // dizer (mesmo host, mesmo veredito) — só os espelhos, logo
                // abaixo, ainda podem ter uma cópia de antes da exclusão (o
                // Wayback, em especial). A própria API já é a "resposta de um
                // servidor" que libera a tentativa neles.
                $candidates = [];
                $last = $api['res'];
                $sawServer = true;
            }
            // Erro inesperado da API (5xx, JSON quebrado): cai no caminho antigo.
        }

        foreach ($candidates as $candidate) {
            $res = $this->get($candidate);

            if (! $res['ok']) {
                $last = $res;

                if ((int) ($res['status'] ?? 0) > 0) {
                    $sawServer = true;
                }

                // Rate limit / timeout / 5xx: as variantes de extensão do imgur
                // apontam para o MESMO objeto no MESMO host — insistir nelas só
                // multiplicaria o tráfego que já está sendo recusado. Os
                // espelhos logo abaixo saem por OUTRA rede e ainda merecem a
                // chance.
                if ($res['transient'] ?? false) {
                    break;
                }

                continue;
            }

            $sawServer = true;

            $found = $this->acceptImage($res, $candidate, $last);
            if ($found !== null) {
                return $found;
            }
        }

        if ($sawServer) {
            foreach ($this->mirrors($url) as $candidate) {
                $res = $this->get($candidate);

                if (! $res['ok']) {
                    // Um espelho recusando (rate limit próprio, 404) não diz
                    // nada sobre os outros dois — cada um é uma rede e, no
                    // caso do Wayback, uma ÉPOCA diferente.
                    $last = $res;
                    continue;
                }

                $found = $this->acceptImage($res, $candidate, $last);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return $this->clean($last ?? $this->err('nenhum candidato de URL'));
    }

    /**
     * Um candidato respondeu 2xx com bytes: é imagem de verdade, ou HTML / o
     * placeholder do imgur disfarçado de sucesso? Devolve o resultado pronto
     * em caso de aceite, ou null — e nesse caso atualiza `$last` com o
     * motivo, para quem chamou (a mesma lógica serve para candidatos diretos
     * e para espelhos).
     *
     * @param array<string, mixed> $res resultado OK de get()
     * @return null|array{ok: bool, bytes: ?string, mime: ?string, ext: ?string, final_url: ?string, error: ?string, transient: bool}
     */
    private function acceptImage(array $res, string $candidate, ?array &$last): ?array
    {
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

            return null;
        }

        // O final_url pega o caso normal (o request seguiu o redirect do
        // imgur direto); o hash pega o que um espelho de terceiro esconde —
        // ele devolve os MESMOS bytes do placeholder com HTTP 200, sem nunca
        // expor o redirect original.
        if ($this->isImgurPlaceholder((string) ($res['final_url'] ?? $candidate))
            || $this->looksLikeImgurRemoved((string) $res['bytes'])) {
            $last = $this->err('imgur: imagem removida (removed.png)', $res['final_url']);

            return null;
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
     * URLs a tentar, em ordem.
     *
     * imgur: a página `imgur.com/<id>` e as variantes `i.imgur.com/<id>.<ext>`
     * apontam para o mesmo objeto — a extensão na URL é só um pedido de
     * conversão. Quando ela não bate, o imgur redireciona para a página HTML;
     * então geramos as variantes do mesmo id, DEPOIS da original.
     *
     * postimg: o host antigo (`s20.postimg.org`, `postimage.org`...) não resolve
     * mais, mas o id e o nome do arquivo continuam válidos no host atual:
     *
     *     http://s20.postimg.org/65kcb53xp/sotd_2_6_2016_1.jpg
     *  -> https://i.postimg.cc/65kcb53xp/sotd_2_6_2016_1.jpg
     *
     * Aqui a reescrita vai ANTES da original: a original é DNS morto, e uma
     * falha de conexão encerra a lista (é transitória) — se ela viesse primeiro
     * a reescrita nunca seria tentada. A original fica como último recurso para
     * o caso de o id não existir mais no host novo.
     *
     * @return array<int, string>
     */
    public function candidates(string $url): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($host !== '' && $host !== 'i.postimg.cc' && preg_match(self::POSTIMG_LEGACY, $host)) {
            // Só o formato /<id>/<arquivo.ext> tem tradução direta; a página
            // /image/<id>/ não diz o nome do arquivo, e sem ele não há URL.
            if (preg_match('#^/([A-Za-z0-9]{6,16})/([^/]+\.[A-Za-z0-9]{2,5})$#', $path, $m)) {
                return [self::POSTIMG_MODERN . '/' . $m[1] . '/' . $m[2], $url];
            }

            return [$url];
        }

        $out = [$url];

        $id = $this->imgurId($url);
        if ($id === null) {
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
     * Id de imagem ÚNICA do imgur na URL, ou null quando não é imgur ou é
     * álbum/galeria (que não têm imagem direta para chutar nem consultar).
     */
    public function imgurId(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($host !== 'imgur.com' && ! str_ends_with($host, '.imgur.com')) {
            return null;
        }

        // /a/xxxx (álbum), /gallery/xxxx e /t/... não são imagens diretas.
        if (preg_match('#^/(a|gallery|t)/#i', $path)) {
            return null;
        }

        $id = pathinfo($path, PATHINFO_FILENAME);

        // Sufixo de tamanho (`T1Ji3QDl.jpg` = large, `...s` = small...) aponta
        // para a MESMA imagem; a API só conhece o id puro.
        if ($id !== '' && preg_match('/^([A-Za-z0-9]{7})[sbtmlh]$/', $id, $m)) {
            $id = $m[1];
        }

        if ($id === '' || ! preg_match('/^[A-Za-z0-9]{5,15}$/', $id)) {
            return null;
        }

        return $id;
    }

    /** Host do imgur, mesmo quando não é imagem única (álbum, galeria) — usado só para decidir se o serveproxy entra no rodízio. */
    private function isImgurHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'imgur.com' || str_ends_with($host, '.imgur.com');
    }

    /**
     * Espelhos de terceiros para uma URL, na ordem do rodízio: cada imagem
     * que cai aqui começa por um espelho diferente do anterior — sem isso o
     * primeiro da lista levaria SEMPRE o primeiro tiro, e seria ele a ganhar
     * um rate limit próprio cedo ou tarde (é exatamente o problema que
     * queremos evitar, só que transferido para o espelho).
     *
     * O serveproxy fica de fora para o imgur: ele recodifica tudo para AVIF —
     * inclusive o placeholder `removed.png` (confirmado: 503 bytes de PNG
     * viram 761 bytes de AVIF), sem nenhum indício no `final_url` de que é
     * ele. Fora do imgur não há esse placeholder conhecido, então ele entra.
     *
     * Público pelo mesmo motivo de {@see candidates()}: é puro (não toca
     * rede, só avança o ponteiro do rodízio) e vale testar direto.
     *
     * @return array<int, string>
     */
    public function mirrors(string $url): array
    {
        $pool = $this->isImgurHost($url)
            ? [self::MIRROR_DUCKDUCKGO, self::MIRROR_WAYBACK]
            : [self::MIRROR_DUCKDUCKGO, self::MIRROR_WAYBACK, self::MIRROR_SERVEPROXY];

        $total = count($pool);
        $offset = $this->mirrorCursor % $total;
        $this->mirrorCursor++;

        $out = [];
        for ($i = 0; $i < $total; $i++) {
            $out[] = $this->buildMirror($pool[($offset + $i) % $total], $url);
        }

        return $out;
    }

    /**
     * @param array{tpl: string, raw: bool} $mirror
     */
    private function buildMirror(array $mirror, string $url): string
    {
        return sprintf($mirror['tpl'], $mirror['raw'] ? $url : rawurlencode($url));
    }

    /**
     * Pré-voo da credencial do imgur: UMA chamada a `/3/credits` (não conta na
     * cota) antes de o run começar. É o que separa, em segundos e com a frase
     * certa, os três estados que em `/3/image/<id>` parecem o mesmo 429:
     *
     *  - Client-ID inválido (403): o cliente é desligado (`markInvalid`) e o
     *    resto do imgur fica adiado — sem gastar minutos de backoff numa chave
     *    que nunca vai passar.
     *  - cota da aplicação zerada (ClientRemaining 0): idem, com o horário em
     *    que volta.
     *  - credencial aceita: devolve quanto o imgur diz que resta HOJE, que é o
     *    número que o admin quer ver no cabeçalho do run.
     *
     * Falha de rede aqui não decide nada: devolve `ok => false` sem `invalid`
     * e o run segue como se não tivesse verificado. Null quando não há
     * credencial configurada.
     *
     * @return null|array{ok: bool, invalid: bool, remaining: ?int, limit: ?int, reset: ?int, error: ?string}
     */
    public function verifyImgur(): ?array
    {
        $client = $this->imgur;
        if ($client === null) {
            return null;
        }

        $res = $this->get($client->creditsEndpoint(), $client->headers(), true);
        $client->observe((array) ($res['headers'] ?? []));

        $status = $res['ok'] ? 200 : (int) ($res['status'] ?? 0);
        $body = (string) ($res['ok'] ? $res['bytes'] : ($res['partial'] ?? ''));

        if ($status === 0) {
            // Nem chegou a falar com o imgur (DNS, TLS, timeout): não é
            // veredito sobre a chave.
            return ['ok' => false, 'invalid' => false, 'remaining' => null, 'limit' => null, 'reset' => null, 'error' => (string) ($res['error'] ?? 'sem resposta')];
        }

        $parsed = $client->parseCredits($status, $body);

        if ($parsed['invalid']) {
            $client->markInvalid();
            $this->announceImgur($client);
        } elseif ($client->remoteExhausted()) {
            $this->announceImgur($client);
        }

        $parsed['reset'] = $client->remoteReset();

        return $parsed;
    }

    /**
     * Erro pronto para uma URL do imgur que NÃO vai ser consultada: credencial
     * recusada, cota da aplicação zerada (pelo imgur) ou o nosso teto do dia.
     * Transitório em todos os casos — a imagem provavelmente existe; é a
     * consulta que não pode ser feita agora.
     *
     * @return array<string, mixed>
     */
    private function imgurUnavailable(ImgurClient $client): array
    {
        $this->announceImgur($client);

        $reset = $client->remoteReset();

        $message = match (true) {
            $client->invalid() => 'imgur API: Client-ID recusado pelo imgur (403 Invalid client_id) — confira a credencial',
            $client->remoteExhausted() => 'imgur API: o imgur reporta a cota deste Client-ID como esgotada (X-RateLimit-ClientRemaining: 0'
                . ($reset === null ? '' : ', zera em ' . (int) ceil($reset / 60) . ' min')
                . ') — confira o Client-ID',
            default => 'imgur API: cota diária esgotada (' . $client->usedToday() . '/' . $client->dailyCap() . ') — volta amanhã',
        };

        return $this->err($message, null, true);
    }

    /**
     * Uma linha no console, UMA vez por run, dizendo por que tudo do imgur
     * passou a ser adiado. Três causas, três avisos: credencial recusada, o
     * imgur dizendo que a cota da aplicação acabou, ou o nosso teto do dia.
     */
    private function announceImgur(ImgurClient $client): void
    {
        if ($this->imgurCapAnnounced || $this->onNotice === null) {
            return;
        }
        $this->imgurCapAnnounced = true;

        $reset = $client->remoteReset();

        ($this->onNotice)([
            'kind'  => match (true) {
                $client->invalid()         => 'imgur_invalid',
                $client->remoteExhausted() => 'imgur_remote_cap',
                default                    => 'imgur_cap',
            },
            'cap'   => $client->dailyCap(),
            'used'  => $client->usedToday(),
            'reset' => $reset === null ? '?' : (string) (int) ceil($reset / 60),
        ]);
    }

    /**
     * Consulta a API do imgur pelo link direto. Devolve `final => true` quando
     * não há mais o que tentar (imagem não existe, ou a cota do dia acabou) —
     * nesse caso `res` já é o erro pronto para devolver.
     *
     * @return array{ok: bool, link: ?string, final: bool, res: array<string, mixed>}
     */
    private function resolveImgur(string $id): array
    {
        $client = $this->imgur;
        assert($client !== null);

        if ($client->exhausted()) {
            return ['ok' => false, 'link' => null, 'final' => true, 'res' => $this->imgurUnavailable($client)];
        }

        $client->consume();

        // IPv4 forçado: a API do imgur não atende IPv6 — nem por interface
        // IPv6 do servidor, nem por resolução AAAA.
        $res = $this->get($client->endpoint($id), $client->headers(), true);

        if (! $res['ok']) {
            $status = (int) ($res['status'] ?? 0);

            // O imgur acabou de dizer que a cota da aplicação zerou (ou que não
            // conhece o Client-ID). Avisar AGORA, com a mensagem certa, em vez
            // de devolver "HTTP 429" e deixar o aviso para a próxima imagem.
            //
            // Não usamos o header desta chamada para bloquear o lote: o
            // pré-voo em /3/credits é a fonte confiável da quota da aplicação.
            // O endpoint de imagem pode responder 429 por limite transitório
            // do pedido/IP; nesse caso o fetcher deve cair na URL direta.
            if ($client->remoteExhausted() && $status !== 429) {
                return ['ok' => false, 'link' => null, 'final' => true, 'res' => $this->imgurUnavailable($client)];
            }

            if ($status === 404 || $status === 400) {
                $parsed = $client->parseResponse($status, (string) ($res['partial'] ?? ''));

                return ['ok' => false, 'link' => null, 'final' => true, 'res' => $this->err((string) $parsed['error'], null, false)];
            }

            // 429/5xx da API: transitório. Tenta a URL direta como fallback;
            // o download de i.imgur.com não depende da cota da API.
            if ($res['transient'] ?? false) {
                return ['ok' => false, 'link' => null, 'final' => false, 'res' => $res];
            }

            return ['ok' => false, 'link' => null, 'final' => false, 'res' => $res];
        }

        $parsed = $client->parseResponse(200, (string) $res['bytes']);

        if ($parsed['ok']) {
            return ['ok' => true, 'link' => $parsed['link'], 'final' => false, 'res' => $res];
        }

        if ($parsed['not_found']) {
            return ['ok' => false, 'link' => null, 'final' => true, 'res' => $this->err((string) $parsed['error'])];
        }

        return ['ok' => false, 'link' => null, 'final' => false, 'res' => $this->err((string) $parsed['error'])];
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
     * @param array<int, string> $headers cabeçalhos extras (API do imgur)
     * @param bool               $ipv4    forçar IPv4 (a API do imgur não atende IPv6)
     * @return array<string, mixed>
     */
    private function get(string $url, array $headers = [], bool $ipv4 = false): array
    {
        if (! preg_match('#^https?://#i', $url)) {
            return $this->err('URL sem esquema http(s)');
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $useCurl = function_exists('curl_init');
        $carry = '';
        $avoid = null;

        // Host desarmado neste run: nem tenta. É o que faz um domínio morto com
        // 300 imagens custar 2 falhas, e não 300 × retentativas.
        if (isset($this->hostTripped[$host])) {
            return $this->err(
                'host inalcançável — pulado após ' . self::HOST_TRIP . ' falhas de conexão seguidas',
                null,
                true,
                defer: true
            );
        }

        for ($attempt = 0; ; $attempt++) {
            $exit = $this->pickExit($host, $avoid, $ipv4);

            if ($exit === null) {
                // Transitório de propósito: as imagens ficam `deferred` e voltam
                // no próximo run. Cair no IP direto por conta própria seria
                // ignorar em silêncio a única coisa que o admin pediu aqui.
                return $this->err('nenhum IP de saída disponível (todos falharam ao conectar)', null, true);
            }

            $this->awaitSlot($host, $exit);

            $res = $useCurl
                ? $this->getCurl($url, $carry, $exit, $headers, $ipv4)
                : $this->getStream($url, $exit, $headers);

            if ($res['ok']) {
                $this->reward($host, $exit);
                unset($this->hostFailures[$host]);

                return $res;
            }

            $errno = (int) ($res['errno'] ?? 0);

            // Falha de CONEXÃO acusa o exit; 429/5xx acusam a URL ou o host.
            // O exit "direto" nunca leva falta: não há outro por onde sair, e
            // uma conexão recusada ali é o DESTINO recusando — três hosts
            // mortos em sequência não podem deixar o run inteiro sem saída.
            if ($exit['kind'] !== 'direct'
                && in_array($errno, self::EXIT_FAULT_CURL, true)
                && $this->exits->strike($exit['key'])
                && $this->onExitDown !== null) {
                ($this->onExitDown)(['exit' => $exit['label'], 'error' => (string) ($res['error'] ?? '')]);
            }

            // Falha de conexão pura (nenhum byte): conta para desarmar o HOST.
            // Só quando o exit em si não é o suspeito — um proxy morto derruba
            // todo host, e a ficha disso é do ExitPool, não do host.
            $connectFailure = in_array($errno, self::CONNECT_CURL, true)
                && ($res['partial'] ?? '') === ''
                && ($errno !== 7 || $exit['kind'] === 'direct');

            if ($connectFailure) {
                $this->hostFailures[$host] = ($this->hostFailures[$host] ?? 0) + 1;

                if ($this->hostFailures[$host] >= self::HOST_TRIP) {
                    $this->hostTripped[$host] = true;

                    if ($this->onNotice !== null) {
                        ($this->onNotice)(['kind' => 'host_tripped', 'host' => $host, 'error' => (string) ($res['error'] ?? '')]);
                    }
                }

                // Adiar em vez de insistir: a URL volta no fim do run.
                if ($this->deferConnectionFailures || isset($this->hostTripped[$host])) {
                    $res['transient'] = true;
                    $res['defer'] = true;

                    return $res;
                }
            }

            if (! ($res['transient'] ?? false) || $attempt >= $this->retries) {
                return $res;
            }

            // 429 da API do imgur com `X-RateLimit-ClientRemaining: 0`: é a cota
            // da APLICAÇÃO (zera em horas) ou um Client-ID desconhecido — os
            // dois casos em que insistir com backoff é esperar à toa. Volta na
            // primeira; quem chamou lê o cabeçalho e avisa com a frase certa.
            if ((int) ($res['status'] ?? 0) === 429 && ImgurClient::clientQuotaGone((array) ($res['headers'] ?? []))) {
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
     * @param ?string $avoid    exit a evitar (o que acabou de ser recusado)
     * @param bool    $ipv4Only pular exits que são interface IPv6 (API do imgur)
     * @return null|array{key: string, label: string, kind: string, value: string}
     */
    private function pickExit(string $host, ?string $avoid = null, bool $ipv4Only = false): ?array
    {
        $live = $this->exits->live();

        if ($ipv4Only) {
            $v4 = array_values(array_filter(
                $live,
                fn (array $exit): bool => ! ($exit['kind'] === 'interface' && str_contains($exit['value'], ':'))
            ));
            // Só há IPv6? Melhor tentar (e falhar com o erro real) do que
            // desistir em silêncio.
            if ($v4 !== []) {
                $live = $v4;
            }
        }

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
     * @param string             $carry   bytes já recebidos numa tentativa anterior; quando
     *                                    não vazio a requisição pede só o restante (Range).
     * @param array<int, string> $headers cabeçalhos extras
     * @return array<string, mixed>
     */
    private function getCurl(string $url, string $carry = '', ?array $exit = null, array $headers = [], bool $ipv4 = false): array
    {
        $ch = curl_init();
        $body = '';
        $tooBig = false;
        $max = $this->maxBytes;
        $offset = strlen($carry);
        $retryAfter = null;
        $headerStatus = 0;
        /** @var array<string, string> $seen cabeçalhos da ÚLTIMA resposta, em minúsculas */
        $seen = [];

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
            CURLOPT_HTTPHEADER     => array_merge(
                [
                    'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Cache-Control: no-cache',
                ],
                $headers === [] ? ['Accept: image/avif,image/webp,image/*,*/*;q=0.8'] : $headers
            ),
            // Verificação TLS ligada por padrão (ver o construtor). Validar o
            // conteúdo por magic bytes garante que é imagem — não que é A
            // imagem que estava lá.
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_HEADER         => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => function ($_ch, string $line) use (&$retryAfter, &$headerStatus, &$seen): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $headerStatus = (int) $m[1];
                    $retryAfter = null; // novo bloco de cabeçalhos (redirect)
                    $seen = [];
                } elseif (preg_match('#^([A-Za-z0-9-]+):\s*(.*?)\s*$#', $line, $m)) {
                    $seen[strtolower($m[1])] = $m[2];
                    if (strcasecmp($m[1], 'Retry-After') === 0) {
                        $retryAfter = $this->parseRetryAfter($m[2]);
                    }
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

        if ($ipv4) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, defined('CURL_IPRESOLVE_V4') ? CURL_IPRESOLVE_V4 : 1);
        }

        if ($this->verifyTls && ($ca = $this->caBundle()) !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
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
            return $this->err('excede o limite de ' . $this->mb($this->maxBytes) . ' MB', $final, headers: $seen);
        }
        if ($errno !== 0) {
            $retriable = in_array($errno, self::RETRIABLE_CURL, true)
                || in_array($errno, self::EXIT_FAULT_CURL, true);

            // 60 = certificado inválido/cadeia quebrada, 51/58/83 = idem em outras
            // camadas: falha DEFINITIVA e com o caminho de saída no texto —
            // retentar não muda o certificado do host.
            if (in_array($errno, [51, 58, 60, 83], true)) {
                return $this->err('curl: ' . $error . ' (certificado TLS inválido; use --insecure para aceitar assim mesmo)', $final, false, 0, null, '', $errno, headers: $seen);
            }

            return $this->err('curl: ' . $error, $final, $retriable, 0, null, $full, $errno, headers: $seen);
        }
        if ($status < 200 || $status >= 300) {
            // O corpo vai junto: a API do imgur explica o erro em JSON.
            return $this->err(
                'HTTP ' . $status . ($status === 429 ? ' (limite de requisições do host)' : ''),
                $final,
                self::isTransientStatus($status),
                $status,
                $retryAfter,
                $full,
                headers: $seen
            );
        }
        if ($full === '') {
            return $this->err('resposta vazia', $final, true, headers: $seen);
        }

        return ['ok' => true, 'bytes' => $full, 'mime' => null, 'ext' => null, 'final_url' => $final, 'error' => null, 'transient' => false, 'headers' => $seen];
    }

    /**
     * @return array<string, mixed>
     */
    private function getStream(string $url, ?array $exit = null, array $headers = []): array
    {
        $extra = array_merge(
            [
                'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control: no-cache',
            ],
            $headers === [] ? ['Accept: image/avif,image/webp,image/*,*/*;q=0.8'] : $headers
        );

        $http = [
            'method'          => 'GET',
            'timeout'         => $this->timeout,
            'follow_location' => 1,
            'max_redirects'   => 6,
            'ignore_errors'   => true,
            'header'          => 'User-Agent: ' . self::UA . "\r\n"
                . implode("\r\n", $extra) . "\r\n",
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
            'ssl'    => array_filter([
                'verify_peer'      => $this->verifyTls,
                'verify_peer_name' => $this->verifyTls,
                'cafile'           => $this->verifyTls ? $this->caBundle() : null,
            ], fn ($v) => $v !== null),
        ]);

        $handle = @fopen($url, 'rb', false, $ctx);
        if ($handle === false) {
            return $this->err('não foi possível abrir a URL', null, true);
        }

        $meta = stream_get_meta_data($handle);
        $status = 0;
        $final = $url;
        $retryAfter = null;
        $seen = [];
        foreach ((array) ($meta['wrapper_data'] ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                $status = (int) $m[1];
                $retryAfter = null;
                $seen = [];
            } elseif (preg_match('#^([A-Za-z0-9-]+):\s*(.*?)\s*$#', (string) $line, $m)) {
                $seen[strtolower($m[1])] = $m[2];
                if (strcasecmp($m[1], 'Location') === 0) {
                    $final = $m[2];
                } elseif (strcasecmp($m[1], 'Retry-After') === 0) {
                    $retryAfter = $this->parseRetryAfter($m[2]);
                }
            }
        }

        $body = (string) stream_get_contents($handle, $this->maxBytes + 1);
        fclose($handle);

        if (strlen($body) > $this->maxBytes) {
            return $this->err('excede o limite de ' . $this->mb($this->maxBytes) . ' MB', $final, headers: $seen);
        }
        if ($status !== 0 && ($status < 200 || $status >= 300)) {
            return $this->err('HTTP ' . $status, $final, self::isTransientStatus($status), $status, $retryAfter, $body, headers: $seen);
        }
        if ($body === '') {
            return $this->err('resposta vazia', $final, true, headers: $seen);
        }

        return ['ok' => true, 'bytes' => $body, 'mime' => null, 'ext' => null, 'final_url' => $final, 'error' => null, 'transient' => false, 'headers' => $seen];
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

    /**
     * Mesmo placeholder, reconhecido pelos BYTES: 503 de tamanho é barato de
     * checar antes do hash, e o tamanho exato já descarta quase tudo que não é
     * o placeholder.
     */
    private function looksLikeImgurRemoved(string $bytes): bool
    {
        return strlen($bytes) === 503 && hash('sha256', $bytes) === self::IMGUR_REMOVED_SHA256;
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
     * @return array{ok: bool, bytes: ?string, mime: ?string, ext: ?string, final_url: ?string, error: ?string, transient: bool, defer: bool}
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
            // `defer` = host não atendeu (DNS/connect/TLS): vale voltar a esta
            // URL no FIM do run, depois que o resto andou.
            'defer'     => (bool) ($res['defer'] ?? false),
        ];
    }

    /**
     * @param array<string, string> $headers
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
        bool $defer = false,
        array $headers = [],
    ): array {
        return [
            'ok'          => false,
            'bytes'       => null,
            'mime'        => null,
            'ext'         => null,
            'final_url'   => $final,
            'error'       => $message,
            'transient'   => $transient,
            'defer'       => $defer,
            'status'      => $status,
            'retry_after' => $retryAfter,
            'partial'     => $partial,
            'errno'       => $errno,
            'headers'     => $headers,
        ];
    }
}
