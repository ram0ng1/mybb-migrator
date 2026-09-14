<?php

namespace Ramon\MybbMigrator\Support;

/**
 * Saneamento do XML gravado em `posts.content` (representação intermediária
 * do s9e/TextFormatter).
 *
 * O libxml recusa qualquer caractere de controle fora de TAB/LF/CR, e o s9e
 * transforma a recusa em exceção no render — o que derruba a resposta inteira
 * da API (índice de tags, lista de discussões) por causa de UM post. O parser
 * do s9e já limpa isso na entrada; o que chega ao banco sujo vem de comandos
 * de reparo que reescrevem o XML diretamente. Fonte conhecida: os placeholders
 * `\x00PROTECTED_N\x00` do `OrphanBbcode::strip` (bug de ordem de restauração,
 * já corrigido) que ficaram literais dentro de `<URL>` e `<CODE>`.
 */
final class XmlText
{
    /** Tudo que o XML 1.0 não aceita como caractere (fora TAB, LF e CR). */
    public const INVALID_CHARS = '/[\x00-\x08\x0B\x0C\x0E-\x1F]/';

    private const LEAKED_PLACEHOLDER = '\x00PROTECTED_\d+\x00';

    public static function isDirty(string $xml): bool
    {
        return preg_match(self::INVALID_CHARS, $xml) === 1;
    }

    /**
     * Devolve o XML renderizável de novo.
     *
     * Os placeholders vazados ocupavam o lugar do `<s>`/`<e>` (a fonte BBCode
     * que o s9e guarda para o unparse, isto é, para editar o post). Quando o
     * placeholder está colado na abertura ou no fecho de `<URL>`/`<CODE>`, a
     * fonte é reconstruída a partir dos atributos; assim o autor volta a ver
     * `[url=…]…[/url]` e `[code]…[/code]` ao editar, em vez do texto nu. Um
     * `<URL>` sem placeholder nenhum é um auto-link e fica como está. Qualquer
     * outro caractere inválido é simplesmente removido.
     */
    public static function clean(string $xml): string
    {
        if (! self::isDirty($xml)) {
            return $xml;
        }

        $p = self::LEAKED_PLACEHOLDER;

        $xml = (string) preg_replace_callback(
            '#<URL\b([^>]*)>' . $p . '#',
            static function (array $m): string {
                $url = preg_match('/\burl="([^"]*)"/', $m[1], $u) ? $u[1] : null;

                return '<URL' . $m[1] . '>' . ($url !== null ? '<s>[url=' . $url . ']</s>' : '');
            },
            $xml
        );
        $xml = (string) preg_replace('#' . $p . '</URL>#', '<e>[/url]</e></URL>', $xml);

        $xml = (string) preg_replace_callback(
            '#<CODE\b([^>]*)>' . $p . '#',
            static function (array $m): string {
                $lang = preg_match('/\blang="([^"]*)"/', $m[1], $l) ? '=' . $l[1] : '';

                return '<CODE' . $m[1] . '><s>[code' . $lang . ']</s>';
            },
            $xml
        );
        $xml = (string) preg_replace('#' . $p . '</CODE>#', '<e>[/code]</e></CODE>', $xml);

        $xml = (string) preg_replace('#' . $p . '#', '', $xml);

        return (string) preg_replace(self::INVALID_CHARS, '', $xml);
    }
}
