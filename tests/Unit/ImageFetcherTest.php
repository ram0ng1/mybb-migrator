<?php

namespace Ramon\MybbMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\MybbMigrator\Support\ImageFetcher;

/**
 * Só a parte pura do fetcher: a geração de URLs candidatas. É ela que resolve o
 * caso relatado do imgur — `i.imgur.com/<id>.jpg` que redireciona para a página
 * `imgur.com/<id>` porque o objeto guardado é PNG.
 */
class ImageFetcherTest extends TestCase
{
    public function test_non_imgur_urls_have_a_single_candidate(): void
    {
        $fetcher = new ImageFetcher();

        $this->assertSame(
            ['https://example.com/foto.jpg'],
            $fetcher->candidates('https://example.com/foto.jpg')
        );
    }

    public function test_imgur_direct_url_gets_extension_variants(): void
    {
        $candidates = (new ImageFetcher())->candidates('https://i.imgur.com/T1Ji3QD.jpg');

        // A original vem primeiro (o caminho feliz não paga round-trip extra).
        $this->assertSame('https://i.imgur.com/T1Ji3QD.jpg', $candidates[0]);
        $this->assertContains('https://i.imgur.com/T1Ji3QD.png', $candidates);
        $this->assertContains('https://i.imgur.com/T1Ji3QD.gif', $candidates);
        $this->assertContains('https://i.imgur.com/T1Ji3QD.webp', $candidates);
        // Sem duplicar a .jpg que já era a original.
        $this->assertSame(array_unique($candidates), $candidates);
    }

    public function test_imgur_page_url_falls_back_to_the_direct_image(): void
    {
        $candidates = (new ImageFetcher())->candidates('https://imgur.com/T1Ji3QD');

        $this->assertSame('https://imgur.com/T1Ji3QD', $candidates[0]);
        $this->assertContains('https://i.imgur.com/T1Ji3QD.png', $candidates);
        $this->assertContains('https://i.imgur.com/T1Ji3QD.jpg', $candidates);
    }

    public function test_imgur_albums_and_galleries_are_not_guessed(): void
    {
        $fetcher = new ImageFetcher();

        // Não são imagens diretas: chutar extensões só geraria 404s.
        $this->assertCount(1, $fetcher->candidates('https://imgur.com/a/qQGfe'));
        $this->assertCount(1, $fetcher->candidates('https://imgur.com/gallery/qQGfe'));
    }

    public function test_extension_is_derived_from_the_mime_type(): void
    {
        $this->assertSame('jpg', ImageFetcher::extensionFor('image/jpeg'));
        $this->assertSame('png', ImageFetcher::extensionFor('IMAGE/PNG'));
        $this->assertSame('webp', ImageFetcher::extensionFor('image/webp'));

        // HTML (a página de "imagem removida") nunca vira arquivo de imagem.
        $this->assertNull(ImageFetcher::extensionFor('text/html'));
        $this->assertNull(ImageFetcher::extensionFor(null));
    }
}
