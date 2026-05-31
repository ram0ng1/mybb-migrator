<?php

namespace Ramon\MybbMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\MybbMigrator\Console\PollOptionsParser;

class PollOptionsParserTest extends TestCase
{
    /**
     * Aceita arrays indexados em PHP serialize() — formato visto em algumas
     * distribuições legadas do MyBB.
     */
    public function test_parses_simple_array(): void
    {
        $input = serialize(['Option A', 'Option B', 'Option C']);

        $this->assertSame(['Option A', 'Option B', 'Option C'], PollOptionsParser::parse($input));
    }

    /**
     * Trim em volta de cada item; suporta UTF-8 já correto.
     */
    public function test_parses_with_special_chars_and_whitespace(): void
    {
        $input = serialize(['  hello  ', 'já']);

        $this->assertSame(['hello', 'já'], PollOptionsParser::parse($input));
    }

    /**
     * Lixo (não-serialize e sem separador padrão MyBB) vira fallback de uma
     * única string trimada; quando o conteúdo não é parseável como múltiplas
     * opções, devolve esse único item como array. Aqui a entrada vazia
     * devolve `[]`.
     */
    public function test_returns_empty_for_invalid_input(): void
    {
        $this->assertSame([], PollOptionsParser::parse(''));
        $this->assertSame([], PollOptionsParser::parse('   '));
    }

    /**
     * `serialize('string')` decodifica para uma string — não array. O parser
     * devolve `[]` em vez de tentar tratar a string como opção única, para
     * que polls malformadas sejam puladas no comando.
     */
    public function test_returns_empty_for_non_array(): void
    {
        $input = serialize('not an array');

        $this->assertSame([], PollOptionsParser::parse($input));
    }

    /**
     * Formato real visto no banco MyBB 1.8: separador literal `||~|~||`
     * entre cada opção, sem PHP serialize().
     */
    public function test_parses_mybb_native_separator(): void
    {
        $input = 'Option A||~|~||Option B||~|~||Option C';

        $this->assertSame(['Option A', 'Option B', 'Option C'], PollOptionsParser::parse($input));
    }
}
