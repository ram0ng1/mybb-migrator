<?php

namespace Ramon\MybbMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\MybbMigrator\Support\ImageOptimizer;

/**
 * O otimizador é puro (bytes entram, bytes saem), então dá para testá-lo de
 * verdade: geramos as imagens com o próprio GD e conferimos o resultado.
 *
 * O que mais importa aqui não é o ganho de tamanho — é a lista de RECUSAS: o
 * caminho "não sei otimizar isto" tem que devolver os bytes originais intactos,
 * porque uma imagem estragada na importação é irrecuperável (a origem é um
 * fórum que está saindo do ar).
 */
class ImageOptimizerTest extends TestCase
{
    protected function setUp(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD ausente');
        }
    }

    public function test_a_large_jpeg_is_resized_and_converted_to_webp(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD sem suporte a webp');
        }

        $original = $this->jpeg(3000, 2000);
        $result = (new ImageOptimizer(true, true, 82, 1600))->optimize($original, 'image/jpeg', 'jpg');

        $this->assertTrue($result['changed']);
        $this->assertSame('webp', $result['ext']);
        $this->assertSame('image/webp', $result['mime']);
        $this->assertLessThan(strlen($original), strlen($result['bytes']));
        $this->assertSame(strlen($original) - strlen($result['bytes']), $result['saved']);

        $info = getimagesizefromstring($result['bytes']);
        $this->assertSame(1600, $info[0]);
        $this->assertSame('image/webp', $info['mime']);
    }

    public function test_resizing_can_be_turned_off_with_a_zero_max_dimension(): void
    {
        $result = (new ImageOptimizer(true, true, 82, 0))->optimize($this->jpeg(2400, 1200), 'image/jpeg', 'jpg');

        $info = getimagesizefromstring($result['bytes']);
        $this->assertSame(2400, $info[0]);
    }

    public function test_transparency_survives_the_conversion(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD sem suporte a webp');
        }

        $result = (new ImageOptimizer())->optimize($this->transparentPng(1200, 900), 'image/png', 'png');

        $this->assertTrue($result['changed']);

        $out = imagecreatefromstring($result['bytes']);
        // Canto superior esquerdo continua totalmente transparente (alfa 127).
        $this->assertSame(127, (imagecolorat($out, 1, 1) >> 24) & 0x7F);
    }

    public function test_animated_gifs_are_left_alone(): void
    {
        // Dois blocos de controle gráfico = animação; o GD só veria o 1º quadro.
        $animated = $this->gif(40, 40) . "\x00\x21\xF9\x04" . "\x00\x21\xF9\x04";

        $result = (new ImageOptimizer())->optimize($animated, 'image/gif', 'gif');

        $this->assertFalse($result['changed']);
        $this->assertSame($animated, $result['bytes']);
        $this->assertSame('gif', $result['ext']);
    }

    public function test_vector_and_avif_are_left_alone(): void
    {
        $optimizer = new ImageOptimizer();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="8" height="8"/></svg>';

        $this->assertFalse($optimizer->optimize($svg, 'image/svg+xml', 'svg')['changed']);
        $this->assertFalse($optimizer->optimize('....ftypavif....', 'image/avif', 'avif')['changed']);
    }

    public function test_undecodable_bytes_come_back_untouched(): void
    {
        $garbage = 'isto não é uma imagem, é o HTML de uma página de erro';

        $result = (new ImageOptimizer())->optimize($garbage, 'image/jpeg', 'jpg');

        $this->assertFalse($result['changed']);
        $this->assertSame($garbage, $result['bytes']);
    }

    public function test_a_gain_below_the_threshold_is_discarded(): void
    {
        // Ganho mínimo impossível (99%) e sem redimensionar: o re-encode até
        // acontece, mas o resultado é descartado e o original prevalece.
        $original = $this->jpeg(800, 600);

        $result = (new ImageOptimizer(true, true, 82, 0, 99))->optimize($original, 'image/jpeg', 'jpg');

        $this->assertSame($original, $result['bytes']);
        $this->assertFalse($result['changed']);
        $this->assertSame('jpg', $result['ext']);
        $this->assertSame(0, $result['saved']);
    }

    public function test_the_output_is_never_larger_than_the_input(): void
    {
        $optimizer = new ImageOptimizer();

        foreach ([$this->jpeg(8, 8), $this->jpeg(640, 480), $this->transparentPng(64, 64)] as $bytes) {
            $this->assertLessThanOrEqual(strlen($bytes), strlen($optimizer->optimize($bytes, null, null)['bytes']));
        }
    }

    public function test_disabling_the_optimizer_passes_everything_through(): void
    {
        $original = $this->jpeg(3000, 2000);

        $result = (new ImageOptimizer(false))->optimize($original, 'image/jpeg', 'jpg');

        $this->assertFalse($result['changed']);
        $this->assertSame($original, $result['bytes']);
        $this->assertSame('jpg', $result['ext']);
        $this->assertFalse((new ImageOptimizer(false))->enabled());
    }

    public function test_without_webp_the_source_format_is_kept(): void
    {
        $original = $this->jpeg(3000, 2000);

        $result = (new ImageOptimizer(true, false, 70, 1200))->optimize($original, 'image/jpeg', 'jpg');

        $this->assertTrue($result['changed']);
        $this->assertSame('jpg', $result['ext']);
        $this->assertSame('image/jpeg', $result['mime']);
        $this->assertSame(1200, getimagesizefromstring($result['bytes'])[0]);
    }

    /** Ruído colorido: comprime mal de propósito, então o tamanho é realista. */
    private function jpeg(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        for ($i = 0; $i < 200; $i++) {
            imagefilledellipse(
                $im,
                random_int(0, $w),
                random_int(0, $h),
                random_int(10, max(11, (int) ($w / 4))),
                random_int(10, max(11, (int) ($h / 4))),
                imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255))
            );
        }

        ob_start();
        imagejpeg($im, null, 95);

        return (string) ob_get_clean();
    }

    private function transparentPng(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledellipse($im, (int) ($w / 2), (int) ($h / 2), (int) ($w / 2), (int) ($h / 2), imagecolorallocate($im, 200, 30, 30));

        ob_start();
        imagepng($im, null, 6);

        return (string) ob_get_clean();
    }

    private function gif(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);

        ob_start();
        imagegif($im);

        return (string) ob_get_clean();
    }
}
