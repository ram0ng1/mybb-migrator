<?php

namespace Ramon\MybbMigrator\Support;

/**
 * Re-encoda a imagem BAIXADA antes de ela virar arquivo local.
 *
 * O acervo de um fórum antigo é o pior caso possível de peso: JPEGs de câmera a
 * 4000 px de largura exibidos num post de 700 px, PNGs de 24 bits usados como
 * foto, BMPs. Internalizar isso como está multiplica o disco do Flarum e o
 * tempo de carregamento de cada tópico — sem ganho nenhum, porque o navegador
 * vai reduzir tudo na hora de desenhar.
 *
 * O que fazemos, nesta ordem:
 *
 *  1. REDIMENSIONA quando o maior lado passa do teto (padrão 1600 px, que ainda
 *     atende telas retina no lightbox do Flarum). É de longe a maior economia.
 *  2. CONVERTE PARA WEBP (padrão), que a 82 de qualidade tipicamente sai 25-35 %
 *     menor que o JPEG equivalente e substitui o PNG fotográfico com folga.
 *     Suporte de navegador é universal desde 2020 — e o alvo aqui é conteúdo
 *     migrado, servido pelo próprio Flarum.
 *  3. Sem WebP disponível (GD sem libwebp), ainda OTIMIZA NO FORMATO DE ORIGEM:
 *     JPEG re-encodado na qualidade pedida, PNG com compressão máxima, BMP (que
 *     não tem compressão nenhuma) virando PNG.
 *
 * Três recusas deliberadas — casos em que re-encodar PIORA:
 *
 *  - GIF/WebP ANIMADO: o GD só enxerga o primeiro quadro; converter destruiria a
 *    animação. Passa intacto.
 *  - SVG e AVIF: vetor não se rasteriza sem perda, e AVIF já é mais eficiente que
 *    o WebP que produziríamos.
 *  - Resultado que não ficou menor: se o re-encode não ganha pelo menos
 *    `minGainPercent`, devolvemos o ORIGINAL. Nunca trocamos bytes por bytes.
 *
 * A classe é pura (bytes entram, bytes saem) e não depende do Flarum, então
 * roda nos testes unitários sem bootstrap.
 */
final class ImageOptimizer
{
    /** MIME de saída por extensão que sabemos gravar. */
    private const OUTPUT_MIME = [
        'webp' => 'image/webp',
        'jpg'  => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
    ];

    public function __construct(
        private bool $enabled = true,
        private bool $webp = true,
        private int $quality = 82,
        private int $maxDimension = 1600,
        private int $minGainPercent = 5,
        /** Teto de pixels a decodificar: o GD usa ~4 bytes por pixel. */
        private int $maxPixels = 25000000,
    ) {
        $this->quality = max(30, min(100, $this->quality));
        $this->maxDimension = max(0, $this->maxDimension);
    }

    /** Há GD para decodificar? Sem isso a otimização é um no-op silencioso. */
    public function available(): bool
    {
        return $this->enabled && function_exists('imagecreatefromstring');
    }

    /** WebP de saída disponível de fato (GD compilado com libwebp)? */
    public function webpAvailable(): bool
    {
        return $this->webp && function_exists('imagewebp');
    }

    /**
     * Configuração efetiva, para quem precisa DESCREVÊ-LA.
     *
     * A frase de log não mora aqui de propósito: esta classe é pura (roda nos
     * testes sem o Flarum) e a frase precisa passar pelo tradutor. Quem monta o
     * texto é o MediaFetchOptions, que tem o translator em mãos.
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function wantsWebp(): bool
    {
        return $this->webp;
    }

    public function quality(): int
    {
        return $this->quality;
    }

    public function maxDimension(): int
    {
        return $this->maxDimension;
    }

    public function minGain(): int
    {
        return $this->minGainPercent;
    }

    /**
     * Devolve os bytes a gravar. Em qualquer dúvida (formato não suportado,
     * animação, decodificação falha, resultado maior) devolve o ORIGINAL — a
     * otimização nunca pode ser motivo de perder uma imagem.
     *
     * `$force` desliga só a regra do ganho mínimo, para o caso em que a
     * conversão é uma QUESTÃO DE CORREÇÃO e não de tamanho: as variantes @2x/@3x
     * de um avatar precisam ter a mesma extensão do arquivo base, porque o
     * srcset do Flarum deriva o caminho delas a partir dele. As recusas de
     * formato (animado, SVG, AVIF, bytes que não decodificam) continuam valendo.
     *
     * @return array{bytes: string, mime: string, ext: string, changed: bool, saved: int, note: ?string}
     */
    public function optimize(string $bytes, ?string $mime, ?string $ext, bool $force = false): array
    {
        $mime = strtolower((string) $mime);
        $ext = strtolower((string) ($ext !== null && $ext !== '' ? $ext : ImageFetcher::extensionFor($mime) ?? ''));
        $keep = [
            'bytes'   => $bytes,
            'mime'    => $mime !== '' ? $mime : 'application/octet-stream',
            'ext'     => $ext !== '' ? $ext : 'bin',
            'changed' => false,
            'saved'   => 0,
            'note'    => null,
        ];

        if (! $this->available() || $bytes === '') {
            return $keep;
        }

        // Vetor e AVIF: re-encodar só pioraria (ver docblock).
        if (in_array($ext, ['svg', 'avif'], true) || str_contains($mime, 'svg')) {
            return $keep;
        }

        // Animação: o GD achataria tudo no primeiro quadro.
        if ($this->isAnimated($bytes, $ext)) {
            return $keep;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return $keep;
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width < 1 || $height < 1 || $width * $height > $this->maxPixels) {
            return $keep;
        }

        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof \GdImage) {
            return $keep;
        }

        try {
            $image = $this->applyOrientation($image, $bytes, $ext);
            [$image, $resized] = $this->resize($image, $this->maxDimension);

            // Lido AQUI: depois do finally a imagem já não existe.
            $finalLongest = max(imagesx($image), imagesy($image));

            $target = $this->targetExtension($ext);
            $encoded = $this->encode($image, $target, $this->qualityFor($ext, $target));
        } catch (\Throwable $e) {
            return $keep;
        } finally {
            // GdImage é objeto desde o PHP 8: some da memória quando a última
            // referência morre (imagedestroy() virou no-op e é deprecada no
            // 8.5). Numa varredura de milhares de imagens isso importa, então
            // soltamos a referência assim que o encode termina.
            unset($image);
        }

        if ($encoded === null || strlen($encoded) < 64) {
            return $keep;
        }

        $original = strlen($bytes);
        $limit = match (true) {
            // Conversão obrigatória: aceita mesmo saindo maior.
            $force   => PHP_INT_MAX,
            // Redimensionou: qualquer ganho serve, o objetivo era a dimensão.
            $resized => $original,
            default  => (int) floor($original * (1 - $this->minGainPercent / 100)),
        };

        if (strlen($encoded) > $limit) {
            return $keep;
        }

        return [
            'bytes'   => $encoded,
            'mime'    => self::OUTPUT_MIME[$target] ?? $keep['mime'],
            'ext'     => $target,
            'changed' => true,
            'saved'   => $original - strlen($encoded),
            'note'    => $this->note($ext, $target, $original, strlen($encoded), $width, $height, $resized ? $finalLongest : 0),
        ];
    }

    /**
     * Extensão de saída. WebP quando dá; senão o formato de origem, com BMP
     * (sem compressão alguma) promovido a PNG.
     */
    private function targetExtension(string $sourceExt): string
    {
        if ($this->webpAvailable()) {
            return 'webp';
        }

        return match ($sourceExt) {
            'jpg', 'jpeg' => 'jpg',
            'png'         => 'png',
            'gif'         => 'gif',
            default       => 'png',
        };
    }

    /**
     * PNG costuma carregar texto/print de tela, onde artefato de compressão
     * aparece muito mais: sobe a qualidade quando a origem era PNG.
     */
    private function qualityFor(string $sourceExt, string $target): int
    {
        if ($target === 'png' || $target === 'gif') {
            return $this->quality;
        }

        return $sourceExt === 'png' ? min(95, max($this->quality, 90)) : $this->quality;
    }

    /**
     * @return array{0: \GdImage, 1: bool} imagem (possivelmente nova) e se houve redimensionamento
     */
    private function resize(\GdImage $image, int $max): array
    {
        if ($max <= 0) {
            return [$image, false];
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= $max) {
            return [$image, false];
        }

        $ratio = $max / $longest;
        $newW = max(1, (int) round($width * $ratio));
        $newH = max(1, (int) round($height * $ratio));

        $canvas = imagecreatetruecolor($newW, $newH);
        // Sem isto o PNG/WebP transparente ganha fundo preto ao ser reamostrado.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);
        unset($image); // ver optimize(): liberação por refcount, não imagedestroy()

        return [$canvas, true];
    }

    /**
     * Foto de celular vem com a rotação no EXIF, e o GD descarta metadados ao
     * re-encodar: sem corrigir aqui, a imagem migrada nasce deitada.
     */
    private function applyOrientation(\GdImage $image, string $bytes, string $ext): \GdImage
    {
        if (! in_array($ext, ['jpg', 'jpeg'], true) || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        $orientation = (int) ($exif['Orientation'] ?? 0);

        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = @imagerotate($image, $angle, 0);
        if (! $rotated instanceof \GdImage) {
            return $image;
        }

        unset($image);

        return $rotated;
    }

    private function encode(\GdImage $image, string $target, int $quality): ?string
    {
        ob_start();

        $ok = match ($target) {
            'webp' => $this->encodeWebp($image, $quality),
            'jpg'  => imagejpeg($this->flatten($image), null, $quality),
            'png'  => imagepng($this->withAlpha($image), null, 9),
            'gif'  => imagegif($image),
            default => false,
        };

        $out = (string) ob_get_clean();

        return $ok && $out !== '' ? $out : null;
    }

    private function encodeWebp(\GdImage $image, int $quality): bool
    {
        return imagewebp($this->withAlpha($image), null, $quality);
    }

    /** Marca a imagem para que o canal alfa seja gravado (webp/png). */
    private function withAlpha(\GdImage $image): \GdImage
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /** JPEG não tem transparência: o que era transparente vira branco. */
    private function flatten(\GdImage $image): \GdImage
    {
        imagealphablending($image, true);
        imagesavealpha($image, false);

        return $image;
    }

    /**
     * GIF animado tem mais de um bloco de controle gráfico; WebP animado traz o
     * chunk `ANIM` no cabeçalho RIFF. Detectar pelos bytes evita depender do
     * Imagick só para essa pergunta.
     */
    private function isAnimated(string $bytes, string $ext): bool
    {
        if ($ext === 'gif') {
            return substr_count($bytes, "\x00\x21\xF9\x04") > 1;
        }

        if ($ext === 'webp') {
            return str_contains(substr($bytes, 0, 64), 'ANIM') || str_contains(substr($bytes, 0, 64), 'ANMF');
        }

        return false;
    }

    private function note(string $from, string $to, int $before, int $after, int $w, int $h, int $newW): ?string
    {
        $pct = $before > 0 ? (int) round(100 * ($before - $after) / $before) : 0;
        $dims = $newW > 0 ? sprintf(', %dpx→%dpx', max($w, $h), $newW) : '';

        return sprintf(
            '%s→%s %s→%s (-%d%%)%s',
            $from,
            $to,
            $this->human($before),
            $this->human($after),
            $pct,
            $dims
        );
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1) . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
