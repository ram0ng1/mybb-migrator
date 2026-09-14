<?php

namespace Ramon\MybbMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\MybbMigrator\Support\XmlText;

/**
 * Reparo do XML deixado sujo pelo bug de restauração do strip-orphan-bbcode
 * (ver OrphanBbcodeTest). Além de tornar o post renderizável de novo, a fonte
 * BBCode de `<URL>`/`<CODE>` é reconstruída para o autor não perder o link ou
 * o bloco de código ao editar.
 */
class XmlTextTest extends TestCase
{
    public function test_clean_xml_is_returned_as_is(): void
    {
        $xml = "<r><p>ok\ttab\nlf</p></r>";

        $this->assertFalse(XmlText::isDirty($xml));
        $this->assertSame($xml, XmlText::clean($xml));
    }

    public function test_rebuilds_url_source_markers_from_leaked_placeholders(): void
    {
        $dirty = "<r><p><URL url=\"https://x.test/a?b=1&amp;c=2\">\x00PROTECTED_0\x00ver\x00PROTECTED_1\x00</URL></p></r>";

        $this->assertTrue(XmlText::isDirty($dirty));
        $this->assertSame(
            '<r><p><URL url="https://x.test/a?b=1&amp;c=2"><s>[url=https://x.test/a?b=1&amp;c=2]</s>ver<e>[/url]</e></URL></p></r>',
            XmlText::clean($dirty)
        );
    }

    public function test_rebuilds_code_source_markers_including_language(): void
    {
        $dirty = "<r><CODE lang=\"php\">\x00PROTECTED_0\x00echo 1;\x00PROTECTED_1\x00</CODE>"
            . "<CODE>\x00PROTECTED_2\x00x\x00PROTECTED_3\x00</CODE></r>";

        $this->assertSame(
            '<r><CODE lang="php"><s>[code=php]</s>echo 1;<e>[/code]</e></CODE>'
            . '<CODE><s>[code]</s>x<e>[/code]</e></CODE></r>',
            XmlText::clean($dirty)
        );
    }

    public function test_autolinked_url_without_placeholders_is_left_alone(): void
    {
        $dirty = "<r><p><URL url=\"https://x.test\">https://x.test</URL> \x00 tail</p></r>";

        $this->assertSame('<r><p><URL url="https://x.test">https://x.test</URL>  tail</p></r>', XmlText::clean($dirty));
    }

    public function test_drops_stray_placeholders_and_every_other_control_char(): void
    {
        $dirty = "<r><p>a\x00PROTECTED_7\x00b\x01c\x0Bd\x1Fe</p></r>";

        $out = XmlText::clean($dirty);

        $this->assertSame('<r><p>abcde</p></r>', $out);
        $this->assertFalse(XmlText::isDirty($out));
    }

    public function test_result_loads_as_xml(): void
    {
        $dirty = "<r><p><URL url=\"https://x.test\">\x00PROTECTED_0\x00x\x00PROTECTED_1\x00</URL>\x00</p></r>";

        $dom = new \DOMDocument();

        $this->assertTrue($dom->loadXML(XmlText::clean($dirty)));
    }
}
