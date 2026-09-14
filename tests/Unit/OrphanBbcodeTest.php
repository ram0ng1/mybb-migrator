<?php

namespace Ramon\MybbMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\MybbMigrator\Support\OrphanBbcode;

/**
 * O strip protege `<s>`/`<e>` e depois `<URL>`/`<CODE>` por cima — proteções
 * aninhadas. A regressão que estes testes travam: restaurar em ordem crescente
 * deixava os placeholders internos (`\x00PROTECTED_0\x00`) literais no XML, e o
 * byte NUL derrubava o render de toda página que incluísse o post.
 */
class OrphanBbcodeTest extends TestCase
{
    private const URL = '<r><p><URL url="https://x.test/a"><s>[url=https://x.test/a]</s>ver<e>[/url]</e></URL></p></r>';

    private const CODE = '<r><CODE><s>[code]</s>[b]literal[/b]<e>[/code]</e></CODE></r>';

    public function test_url_with_nested_source_markers_survives_untouched(): void
    {
        $this->assertSame(self::URL, OrphanBbcode::strip(self::URL));
    }

    public function test_code_block_keeps_its_bbcode_verbatim(): void
    {
        $this->assertSame(self::CODE, OrphanBbcode::strip(self::CODE));
    }

    public function test_never_leaves_a_placeholder_or_nul_byte_behind(): void
    {
        $xml = '<r><p>[b]x<URL url="https://x.test"><s>[url]</s>https://x.test<e>[/url]</e></URL>[/color]'
            . '<CODE><s>[code]</s>y<e>[/code]</e></CODE>[/size]</p></r>';

        $out = OrphanBbcode::strip($xml);

        $this->assertStringNotContainsString("\x00", $out);
        $this->assertStringNotContainsString('PROTECTED_', $out);
        $this->assertSame(
            '<r><p>x<URL url="https://x.test"><s>[url]</s>https://x.test<e>[/url]</e></URL>'
            . '<CODE><s>[code]</s>y<e>[/code]</e></CODE></p></r>',
            $out
        );
    }

    public function test_strips_orphan_markers_outside_protected_regions(): void
    {
        $this->assertSame(
            '<r><p>a <B><s>[b]</s>b<e>[/b]</e></B> c</p></r>',
            OrphanBbcode::strip('<r><p>[font=Arial]a <B><s>[b]</s>b<e>[/b]</e></B> c[/font][/size]</p></r>')
        );
    }
}
