<?php

namespace Ramon\MybbMigrator\Support;

/**
 * Remove marcadores BBCode literais órfãos (`[b]`, `[/color]`, `[font=...]`,
 * `[/size]` etc.) de um XML já parseado pelo s9e, preservando o markup válido
 * e os trechos que podem conter `[bbcode]` legítimo.
 *
 * Lógica pura (sem Flarum) para ser testável; o `StripOrphanBbcodeCommand`
 * apenas percorre a tabela e delega para cá.
 */
final class OrphanBbcode
{
    private const TAG_LIST = 'b|i|u|s|strike|del|ins|color|font|size|align|center|left|right|justify|hr|indent|mention|sub|sup';

    private const PLACEHOLDER = "\x00PROTECTED_%d\x00";

    /**
     * Estratégia: preserva o conteúdo de `<s>...</s>` / `<e>...</e>` (fonte
     * original do s9e), `<CODE>...</CODE>` e `<URL>...</URL>` (que podem ter
     * `[bbcode]` legítimo), faz strip nas demais regiões usando um placeholder,
     * e recompõe.
     *
     * As proteções ANINHAM: todo `<CODE>` e todo `<URL>` escrito pelo usuário
     * carrega `<s>`/`<e>` dentro, que já viraram placeholder quando o elemento
     * externo é protegido. Por isso a restauração vai do último placeholder
     * para o primeiro — ao devolver o externo, os internos voltam ao texto e
     * ainda serão visitados. Na ordem crescente eles ficavam para trás como
     * `\x00PROTECTED_0\x00` literal no XML gravado, e o byte NUL derrubava o
     * render (`Cannot load XML: PCDATA invalid Char value 0`) de qualquer
     * página que incluísse o post. Ver `XmlText::clean` para o reparo.
     */
    public static function strip(string $xml): string
    {
        $protected = [];

        $protect = static function (string $s) use (&$protected): string {
            $key = sprintf(self::PLACEHOLDER, count($protected));
            $protected[] = $s;
            return $key;
        };

        $xml = preg_replace_callback('#<s>.*?</s>#s', static fn (array $m): string => $protect($m[0]), $xml) ?? $xml;
        $xml = preg_replace_callback('#<e>.*?</e>#s', static fn (array $m): string => $protect($m[0]), $xml) ?? $xml;
        $xml = preg_replace_callback('#<CODE\b.*?</CODE>#s', static fn (array $m): string => $protect($m[0]), $xml) ?? $xml;
        $xml = preg_replace_callback('#<URL\b.*?</URL>#s', static fn (array $m): string => $protect($m[0]), $xml) ?? $xml;

        $xml = (string) preg_replace('#\[/?(?:' . self::TAG_LIST . ')\b[^\]]*\]#i', '', $xml);

        // url/img: só os marcadores SEM dado — `[/url]`, `[img]`, `[/img]` órfãos
        // (sobra de aninhamento malformado do MyBB, ex.: `…[/IMG][/URL][/img]`).
        // `[url=...]` de ABERTURA NÃO é removido (carrega a URL); o conteúdo
        // legítimo já está protegido em <URL>/<s>/<e>.
        $xml = (string) preg_replace('#\[/url\]#i', '', $xml);
        $xml = (string) preg_replace('#\[/?img\]#i', '', $xml);

        for ($i = count($protected) - 1; $i >= 0; $i--) {
            $xml = str_replace(sprintf(self::PLACEHOLDER, $i), $protected[$i], $xml);
        }

        return $xml;
    }
}
