<?php

namespace Ramon\MybbMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\MybbMigrator\BBCode\Converter;

/**
 * Fidelidade de espaçamento (nl2br do MyBB). O MyBB renderiza CADA quebra de
 * linha como um `<br>` e nunca colapsa linhas em branco consecutivas; o litedown
 * (flarum/markdown) junta qualquer número de linhas em branco numa única quebra
 * de parágrafo. O Converter preenche cada linha vazia com um ZERO-WIDTH SPACE
 * (U+200B) para que cada `\n` volte a virar um `<br>` no Flarum — reproduzindo o
 * layout do MyBB byte a byte. Estes testes travam esse contrato.
 */
class ConverterTest extends TestCase
{
    private const Z = "\u{200B}"; // marcador invisível de linha em branco

    public function test_single_line_break_is_preserved_verbatim(): void
    {
        // Uma quebra simples já vira <br> no litedown (enableAutoLineBreaks) — não
        // precisa de marcador.
        $this->assertSame("A\nB", Converter::convert("A\r\nB"));
    }

    public function test_one_blank_line_is_kept_as_a_break(): void
    {
        // MyBB: A<br><br>B. O marcador na linha vazia evita o colapso em parágrafo.
        $this->assertSame("A\n" . self::Z . "\nB", Converter::convert("A\r\n\r\nB"));
    }

    public function test_consecutive_blank_lines_are_each_preserved(): void
    {
        // O ponto central: 2/3 linhas em branco NÃO colapsam para uma só.
        $this->assertSame("A\n" . self::Z . "\n" . self::Z . "\nB", Converter::convert("A\r\n\r\n\r\nB"));
        $this->assertSame(
            "A\n" . self::Z . "\n" . self::Z . "\n" . self::Z . "\nB",
            Converter::convert("A\r\n\r\n\r\n\r\nB")
        );
    }

    public function test_leading_and_trailing_blank_lines_are_trimmed(): void
    {
        // Linhas em branco nas extremidades são ruído de edição (o próprio MyBB
        // faz trim ao salvar) — não viram espaço no topo/rodapé do post.
        $this->assertSame('X', Converter::convert("\r\n\r\nX\r\n\r\n"));
    }

    public function test_code_block_internal_blank_lines_stay_verbatim(): void
    {
        // O conteúdo de [code] é preservado intacto: nenhum marcador é injetado
        // dentro dele (senão apareceria no bloco de código).
        $this->assertSame(
            "a\n" . self::Z . "\n[code]x\n\n\ny[/code]\n" . self::Z . "\nb",
            Converter::convert("a\r\n\r\n[code]x\r\n\r\n\r\ny[/code]\r\n\r\nb")
        );
    }

    public function test_numbered_lines_are_neutralized_without_a_visible_backslash(): void
    {
        // O MyBB renderiza `1. ` / `2. ` como texto literal (nl2br, sem markdown);
        // o litedown transformaria numa <ol>. Neutralizamos prefixando a linha com
        // U+200B — NUNCA com `\`: o litedown não escapa `.`, então a barra sobraria
        // visível no post renderizado (o bug `1\.`). Cada marcador precisa do
        // prefixo (inclusive os itens separados por linha em branco).
        $out = Converter::convert("1. first\r\n\r\n2. second");

        $this->assertSame(
            self::Z . "1. first\n" . self::Z . "\n" . self::Z . "2. second",
            $out
        );
        $this->assertStringNotContainsString('\\', $out);
    }

    public function test_bullets_and_headings_are_neutralized_without_a_backslash(): void
    {
        // Mesmo princípio para `- `/`+ ` (bullets) e `# ` (heading ATX): o litedown
        // não escapa `-`/`+`/`#`, então usamos U+200B em vez de `\`.
        $this->assertSame(self::Z . '- a', Converter::convert('- a'));
        $this->assertSame(self::Z . '+ a', Converter::convert('+ a'));
        $this->assertSame(self::Z . '# a', Converter::convert('# a'));
    }

    public function test_hr_keeps_real_blank_lines_around_the_rule(): void
    {
        // O thematic break (`---`) precisa de linhas EM BRANCO de verdade ao redor;
        // um marcador colado viraria sublinhado setext (heading). O Converter
        // recria o vão real ao restaurar o [hr].
        $out = Converter::convert("above\r\n\r\n[hr]\r\n\r\nbelow");

        $this->assertStringContainsString("\n\n---\n\n", $out);
        $this->assertStringNotContainsString(self::Z . "\n---", $out);
        $this->assertStringNotContainsString("---\n" . self::Z, $out);
    }
}
