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

    public function __construct(
        private int $timeout = 20,
        private int $maxBytes = 10485760,
    ) {
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
     * @return array{ok: bool, bytes: ?string, mime: ?string, ext: ?string, final_url: ?string, error: ?string}
     */
    public function fetchImage(string $url): array
    {
        $last = null;

        foreach ($this->candidates($url) as $candidate) {
            $res = $this->get($candidate);

            if (! $res['ok']) {
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
            ];
        }

        return $last ?? $this->err('nenhum candidato de URL');
    }

    /**
     * Baixa um arquivo qualquer (anexo do MyBB), sem exigir que seja imagem.
     *
     * @return array{ok: bool, bytes: ?string, mime: ?string, ext: ?string, final_url: ?string, error: ?string}
     */
    public function fetchFile(string $url): array
    {
        $res = $this->get($url);
        if (! $res['ok']) {
            return $res;
        }

        if ($this->looksLikeHtml((string) $res['bytes'])) {
            return $this->err('destino devolveu HTML (login exigido ou arquivo ausente)', $res['final_url']);
        }

        $mime = $this->sniff((string) $res['bytes']);

        return [
            'ok'        => true,
            'bytes'     => $res['bytes'],
            'mime'      => $mime,
            'ext'       => self::extensionFor($mime),
            'final_url' => $res['final_url'],
            'error'     => null,
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
     * GET cru com teto de bytes. Usa cURL quando disponível (redirects + abort no
     * meio do download); cai para stream wrapper caso contrário.
     *
     * @return array{ok: bool, bytes: ?string, mime: ?string, ext: ?string, final_url: ?string, error: ?string}
     */
    private function get(string $url): array
    {
        if (! preg_match('#^https?://#i', $url)) {
            return $this->err('URL sem esquema http(s)');
        }

        return function_exists('curl_init')
            ? $this->getCurl($url)
            : $this->getStream($url);
    }

    /**
     * @return array{ok: bool, bytes: ?string, mime: ?string, ext: ?string, final_url: ?string, error: ?string}
     */
    private function getCurl(string $url): array
    {
        $ch = curl_init();
        $body = '';
        $tooBig = false;
        $max = $this->maxBytes;

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_ENCODING       => '',
            // Fóruns antigos e CDNs de imagem frequentemente têm cadeia de
            // certificados quebrada; o conteúdo é validado por magic bytes.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HEADER         => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => function ($_ch, string $chunk) use (&$body, &$tooBig, $max): int {
                $body .= $chunk;
                if (strlen($body) > $max) {
                    $tooBig = true;

                    return -1; // aborta o download
                }

                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        // sem curl_close(): handles de cURL são objetos desde o PHP 8.0 e a
        // função virou deprecada no 8.5 — o recurso é liberado sozinho.

        if ($tooBig) {
            return $this->err('excede o limite de ' . $this->mb($this->maxBytes) . ' MB', $final);
        }
        if ($errno !== 0) {
            return $this->err('curl: ' . $error, $final);
        }
        if ($status < 200 || $status >= 300) {
            return $this->err('HTTP ' . $status, $final);
        }
        if ($body === '') {
            return $this->err('resposta vazia', $final);
        }

        return ['ok' => true, 'bytes' => $body, 'mime' => null, 'ext' => null, 'final_url' => $final, 'error' => null];
    }

    /**
     * @return array{ok: bool, bytes: ?string, mime: ?string, ext: ?string, final_url: ?string, error: ?string}
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
                'header'          => 'User-Agent: ' . self::UA . "\r\n",
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $handle = @fopen($url, 'rb', false, $ctx);
        if ($handle === false) {
            return $this->err('não foi possível abrir a URL');
        }

        $meta = stream_get_meta_data($handle);
        $status = 0;
        $final = $url;
        foreach ((array) ($meta['wrapper_data'] ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                $status = (int) $m[1];
            } elseif (preg_match('#^Location:\s*(.+)$#i', (string) $line, $m)) {
                $final = trim($m[1]);
            }
        }

        $body = (string) stream_get_contents($handle, $this->maxBytes + 1);
        fclose($handle);

        if (strlen($body) > $this->maxBytes) {
            return $this->err('excede o limite de ' . $this->mb($this->maxBytes) . ' MB', $final);
        }
        if ($status !== 0 && ($status < 200 || $status >= 300)) {
            return $this->err('HTTP ' . $status, $final);
        }
        if ($body === '') {
            return $this->err('resposta vazia', $final);
        }

        return ['ok' => true, 'bytes' => $body, 'mime' => null, 'ext' => null, 'final_url' => $final, 'error' => null];
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
     * @return array{ok: bool, bytes: ?string, mime: ?string, ext: ?string, final_url: ?string, error: ?string}
     */
    private function err(string $message, ?string $final = null): array
    {
        return ['ok' => false, 'bytes' => null, 'mime' => null, 'ext' => null, 'final_url' => $final, 'error' => $message];
    }
}
