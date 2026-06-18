<?php

namespace Ramon\MybbMigrator\BBCode;

use Ramon\MybbMigrator\Support\Charset;
use Ramon\MybbMigrator\Support\TapatalkEmoji;

/**
 * Converte o BBCode de uma mensagem do MyBB para o formato esperado pelo
 * Flarum (flarum/bbcode + flarum/markdown + flarum/mentions). O pipeline:
 *  1. Repara mojibake (UTF-8 duplo) via Charset::fix.
 *  2. Normaliza quebras de linha — colapsa o artefato `\r\r\n` (CR duplo) para
 *     UMA quebra, evitando dobrar o espaçamento e quebrar BBCode inline.
 *  3. Reescreve quotes top-level do MyBB:
 *        [quote='Autor' pid='N' dateline='T']...[/quote]
 *     vira: @"Autor"#pN + [quote="Autor"]...[/quote]
 *     (o token @"Autor"#pN é a sintaxe que o flarum/mentions parseia como
 *     POSTMENTION; isso cobre a fidelidade de citações + menções)
 *  4. Drop dos tokens [attachment=N] (anexos não migrados).
 *  5. Converte [video=...]url[/video] para a URL solta.
 *  6. Converte [email=addr]label[/email] para [email]addr[/email].
 *  7. Mantém todo o restante (b, i, u, s, color, size, align, hr, code, list,
 *     img, url, table) — flarum/bbcode renderiza nativamente.
 *  8. preserveLiteralLayout: escapa marcadores de pseudo-lista (`* `, `- `, …)
 *     e redistribui BBCode inline por blocos, para o layout ficar fiel ao MyBB
 *     (nl2br, sem markdown) — sem listas acidentais nem tags órfãs.
 */
final class Converter
{
    /** Tags inline cuja formatação o markdown não consegue atravessar blocos. */
    private const INLINE_TAGS = 'b|i|u|s|color|size|font';

    public static function convert(string $rawMessage): string
    {
        $text = Charset::fix($rawMessage);
        $text = TapatalkEmoji::convert($text);
        $text = self::normalizeNewlines($text);
        $text = self::rewriteQuotes($text);
        $text = self::dropAttachmentTokens($text);
        $text = self::rewriteVideos($text);
        $text = self::rewriteEmails($text);
        $text = self::linkBareUrls($text);
        $text = self::normalizeBbcode($text);
        $text = self::preserveLiteralLayout($text);

        return $text;
    }

    /**
     * Auto-linka endereços `www.…` soltos (sem esquema), como o MyBB fazia. O
     * litedown já auto-linka `http(s)://` e e-mails, mas NÃO `www.` — então só
     * esse caso precisa ser embrulhado em `[url]`. Não toca em URLs já dentro de
     * `[url]`/`[img]`/`[email]`/`[code]`, nem no `www.` que já faz parte de um
     * `http://www…` (lookbehind), e mantém a pontuação final fora do link.
     */
    private static function linkBareUrls(string $text): string
    {
        $protected = [];
        $protect = static function (array $m) use (&$protected): string {
            $key = "\x00URL" . count($protected) . "\x00";
            $protected[] = $m[0];
            return $key;
        };
        $text = (string) preg_replace_callback('#\[code(?:=[^\]]*)?\].*?\[/code\]#is', $protect, $text);
        $text = (string) preg_replace_callback('#\[url\b[^\]]*\].*?\[/url\]#is', $protect, $text);
        $text = (string) preg_replace_callback('#\[img\b[^\]]*\].*?\[/img\]#is', $protect, $text);
        $text = (string) preg_replace_callback('#\[email\b[^\]]*\].*?\[/email\]#is', $protect, $text);

        $text = (string) preg_replace_callback(
            '#(?<![\w/@.])www\.[^\s<>\[\]()]+#i',
            static function (array $m): string {
                $url = $m[0];
                $trail = '';
                if (preg_match('/[.,;:!?\'")]+$/', $url, $tm)) {
                    $trail = (string) $tm[0];
                    $url = substr($url, 0, -strlen($trail));
                }
                if ($url === '' || ! str_contains($url, '.')) {
                    return $m[0];
                }
                return '[url=http://' . $url . ']' . $url . '[/url]' . $trail;
            },
            $text
        );

        foreach ($protected as $i => $original) {
            $text = str_replace("\x00URL{$i}\x00", $original, $text);
        }

        return $text;
    }

    /**
     * Normaliza quebras de linha colapsando o artefato `\r\r\n` (CR duplo, vindo
     * de export/import duplicado do MyBB) para UMA quebra. Sem isto, cada quebra
     * simples (`\r\r\n`) vira `\n\n` (separador de parágrafo do markdown),
     * dobrando o espaçamento E quebrando BBCode inline que atravessa as linhas.
     *
     *  - `\r\r\n` (quebra simples) → `\n`
     *  - `\r\r\n\r\r\n` (linha em branco real) → `\n\n`
     *  - `\r\n` / `\r` soltos → `\n`
     */
    private static function normalizeNewlines(string $text): string
    {
        $text = (string) preg_replace('/\r+\n/', "\n", $text);
        return str_replace("\r", "\n", $text);
    }

    /**
     * Reproduz fielmente o layout literal do MyBB (que renderiza com nl2br e SEM
     * markdown), corrigindo dois conflitos do litedown:
     *
     *  1. Listas acidentais: linhas iniciadas por `* `, `- `, `+ `, `N. `, `N) `
     *     viram listas markdown — escapamos o marcador para ele ficar literal.
     *  2. BBCode inline (`[b][color][size]…`) que envolve conteúdo com linhas em
     *     branco: o markdown corta em blocos e o s9e fecha a formatação cedo,
     *     deixando tags de fechamento órfãs como texto. Redistribuímos o stack
     *     inline por cada bloco para a formatação sobreviver.
     *  3. Bloco de código por indentação: uma linha iniciada por TAB ou 4+
     *     espaços vira `<code>` no markdown. O MyBB colapsa esse espaço em branco
     *     (nunca mostra indentação), então removemos o whitespace inicial de cada
     *     linha para o texto ficar literal — sem caixas de código acidentais.
     *
     * O conteúdo de `[code]…[/code]` é preservado intacto (verbatim).
     */
    private static function preserveLiteralLayout(string $text): string
    {
        $protected = [];
        $text = (string) preg_replace_callback(
            '#\[code(?:=[^\]]*)?\].*?\[/code\]#is',
            static function (array $m) use (&$protected): string {
                $key = "\x00CODE" . count($protected) . "\x00";
                $protected[] = $m[0];
                return $key;
            },
            $text
        );

        // Remove indentação inicial (TAB / espaços) que o markdown transformaria
        // em bloco de código — fiel ao MyBB, que colapsa o whitespace inicial.
        $text = (string) preg_replace('/^[ \t]+/m', '', $text);

        $text = self::redistributeInline($text);
        $text = self::escapeMarkdownStarters($text);

        // Restaura a sentinela do [hr] como thematic break real (depois do escape,
        // para o `---` legítimo do [hr] sobreviver).
        $text = str_replace("\x00HRULE\x00", '---', $text);

        foreach ($protected as $i => $original) {
            $text = str_replace("\x00CODE{$i}\x00", $original, $text);
        }

        return $text;
    }

    /**
     * Escapa construções de início de linha que o litedown interpretaria como
     * blocos markdown, preservando-as como texto literal — fiel ao MyBB (sem
     * markdown). Marcadores BBCode `[*]`/`[list]` NÃO são tocados.
     *
     *  - listas: `* `, `- `, `+ `, `N. `, `N) `
     *  - headings ATX: `# ` … `###### `
     *  - blockquote: `> `
     *  - headings setext / thematic break: linha só com `--`/`==`/`***`/`___`
     *    (o `[hr]` legítimo usa a sentinela e é restaurado depois deste passo).
     */
    private static function escapeMarkdownStarters(string $text): string
    {
        $text = (string) preg_replace('/^(\s*)([*+\-]) /m', '$1\\\\$2 ', $text);
        $text = (string) preg_replace('/^(\s*)(\d+)([.)]) /m', '$1$2\\\\$3 ', $text);
        $text = (string) preg_replace('/^(#{1,6} )/m', '\\\\$1', $text);
        $text = (string) preg_replace('/^(\s*)>/m', '$1\\\\>', $text);
        // setext underline / thematic break (linha só com o marcador). UM único
        // `-` ou `=` já vira heading no CommonMark. `-` `*` `_` → backslash (o
        // litedown os escapa); `=` → ZERO-WIDTH SPACE no início (o litedown NÃO
        // escapa `=`), invisível e quebrando o setext.
        $text = (string) preg_replace('/^(-+[ \t]*)$/m', '\\\\$1', $text);
        $text = (string) preg_replace('/^([*_]{3,}[ \t]*)$/m', '\\\\$1', $text);
        $text = (string) preg_replace('/^(=+[ \t]*)$/m', "\u{200B}$1", $text);
        return $text;
    }

    /**
     * Redistribui um stack contíguo de tags inline (`[b][color][size]…
     * [/size][/color][/b]`) que envolve conteúdo contendo linhas em branco: cada
     * bloco separado por linha em branco passa a ser envolvido independentemente
     * pelo mesmo stack, de modo que a formatação sobreviva às quebras de bloco do
     * markdown. Conservador: só age quando o corpo não contém outras tags inline
     * (caso bem-comportado, sem aninhamento ambíguo).
     */
    private static function redistributeInline(string $text): string
    {
        $open = '(?:\[(?:' . self::INLINE_TAGS . ')(?:=[^\]]*)?\])+';
        $close = '(?:\[/(?:' . self::INLINE_TAGS . ')\])+';

        return (string) preg_replace_callback(
            '#(' . $open . ')(.*?)(' . $close . ')#s',
            static function (array $m): string {
                $openStack = $m[1];
                $body = $m[2];
                $closeStack = $m[3];

                // sem linha em branco no corpo: nada a redistribuir.
                if (! preg_match('/\n[ \t]*\n/', $body)) {
                    return $m[0];
                }
                // corpo com outras tags inline: aninhamento ambíguo — não mexe.
                if (preg_match('#\[/?(?:' . self::INLINE_TAGS . ')\b#i', $body)) {
                    return $m[0];
                }

                $segments = (array) preg_split('/(\n[ \t]*\n)/', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
                $out = '';
                foreach ($segments as $seg) {
                    if (preg_match('/^\n[ \t]*\n$/', (string) $seg)) {
                        $out .= $seg; // preserva a separação em branco
                        continue;
                    }
                    if ($seg === '') {
                        continue;
                    }
                    $out .= $openStack . $seg . $closeStack;
                }
                return $out;
            },
            $text
        );
    }

    /**
     * Normaliza BBCode do MyBB para o subset que flarum/bbcode entende.
     *
     *  - `[size=medium]` etc → `[size=N]` (s9e SIZE só aceita número).
     *  - `[font=...]` → strip os marcadores (conteúdo preservado).
     *  - `[align=center]` → `[center]…[/center]`.
     *  - `[align=left|right|justify]` → strip os marcadores.
     *  - `[hr]` → `\n---\n` (litedown thematic break).
     *  - `[php]`/`[/php]` → `[code]`/`[/code]`.
     *  - `[indent]…[/indent]` → strip os marcadores.
     */
    public static function normalizeBbcode(string $text): string
    {
        // [size=NAME] → [size=N]
        $sizeMap = [
            'xx-small' => 8,
            'x-small'  => 10,
            'small'    => 12,
            'medium'   => 14,
            'large'    => 18,
            'x-large'  => 24,
            'xx-large' => 32,
        ];
        $text = (string) preg_replace_callback(
            '/\[size=([^\]]+)\]/i',
            static function (array $m) use ($sizeMap): string {
                $raw = strtolower(trim((string) $m[1]));
                if (isset($sizeMap[$raw])) {
                    return '[size=' . $sizeMap[$raw] . ']';
                }
                if (is_numeric($raw)) {
                    return '[size=' . (int) $raw . ']';
                }
                // valor inválido — drop a tag inteira (no callback de ambas as tags abaixo).
                return '';
            },
            $text
        );
        // Limpa [/size] órfão deixado pelo drop acima — só remove se não houver
        // [size=N] correspondente antes. Implementação simples: se [/size] aparece
        // sem um [size= numérico no escopo anterior, deixa pro renderer ignorar.

        // [font=...] e [/font] → strip marcadores, manter conteúdo.
        $text = (string) preg_replace('#\[font=[^\]]*\]#i', '', $text);
        $text = (string) preg_replace('#\[/font\]#i', '', $text);

        // [align=center] → [center], [/align] dentro de center → [/center];
        // outros aligns → strip marcadores.
        $text = (string) preg_replace_callback(
            '#\[align=([^\]]+)\](.*?)\[/align\]#is',
            static function (array $m): string {
                $align = strtolower(trim((string) $m[1]));
                $body = (string) $m[2];
                if ($align === 'center') {
                    return '[center]' . $body . '[/center]';
                }
                return $body;
            },
            $text
        );

        // [hr] / [hr/] → sentinela (restaurada para `---` em preserveLiteralLayout,
        // DEPOIS do escape de setext/thematic-break, para não ser neutralizada).
        $text = (string) preg_replace('#\[hr\s*/?\]#i', "\n\n\x00HRULE\x00\n\n", $text);

        // [php]…[/php] → [code]…[/code]
        $text = (string) preg_replace('#\[php\]#i', '[code]', $text);
        $text = (string) preg_replace('#\[/php\]#i', '[/code]', $text);

        // [indent]…[/indent] → strip marcadores
        $text = (string) preg_replace('#\[/?indent\]#i', '', $text);

        return $text;
    }

    /**
     * Caminha pelo texto encontrando pares [quote ... ][/quote] balanceados e
     * reescreve apenas o nível mais externo de cada cadeia. Nesteds ficam
     * intactos para o flarum/bbcode renderizar.
     */
    private static function rewriteQuotes(string $text): string
    {
        $out = '';
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            $openPos = self::findQuoteOpenTag($text, $offset);

            if ($openPos === null) {
                $out .= substr($text, $offset);
                break;
            }

            $out .= substr($text, $offset, $openPos['start'] - $offset);

            $closePos = self::findMatchingCloseTag($text, $openPos['end']);

            if ($closePos === null) {
                $out .= substr($text, $openPos['start']);
                break;
            }

            $innerContent = substr($text, $openPos['end'], $closePos['start'] - $openPos['end']);
            $author = self::sanitizeDisplayName($openPos['author']);
            $pid = $openPos['pid'];

            $mention = '';
            if ($pid !== null && $author !== '') {
                $prefix = ($out !== '' && substr($out, -1) !== "\n") ? "\n" : '';
                $mention = $prefix . '@"' . $author . '"#p' . $pid . "\n";
            }

            $normalizedOpen = $author !== '' ? '[quote="' . $author . '"]' : '[quote]';
            $out .= $mention . $normalizedOpen . $innerContent . '[/quote]';

            $offset = $closePos['end'];
        }

        return $out;
    }

    /**
     * Procura a próxima abertura [quote...] a partir de $offset, devolvendo o
     * intervalo da tag e os atributos (author, pid). Devolve null se não houver.
     *
     * @return array{start:int,end:int,author:string,pid:?int}|null
     */
    private static function findQuoteOpenTag(string $text, int $offset): ?array
    {
        $pattern = '/\[quote(\s[^\]]*)?\]/i';

        if (! preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
            return null;
        }

        $start = (int) $m[0][1];
        $tagLength = strlen((string) $m[0][0]);
        $rawAttrs = trim((string) ($m[1][0] ?? ''));

        $author = '';
        $pid = null;

        if ($rawAttrs !== '') {
            if (preg_match("/^=([^ \t]+?)(?:\s|$)/", $rawAttrs, $am)) {
                $author = self::stripQuotes($am[1]);
            } elseif (preg_match("/^=(['\"])(.*?)\\1/", $rawAttrs, $am)) {
                $author = $am[2];
            }

            if (preg_match("/\bpid\s*=\s*['\"]?(\d+)['\"]?/i", $rawAttrs, $am)) {
                $pid = (int) $am[1];
            }
        }

        return [
            'start'  => $start,
            'end'    => $start + $tagLength,
            'author' => trim($author),
            'pid'    => $pid,
        ];
    }

    /**
     * Encontra o [/quote] que fecha o quote aberto em $openEnd, respeitando
     * nesteds. Devolve as posições start/end da tag de fechamento.
     *
     * @return array{start:int,end:int}|null
     */
    private static function findMatchingCloseTag(string $text, int $openEnd): ?array
    {
        $depth = 1;
        $cursor = $openEnd;
        $length = strlen($text);

        while ($cursor < $length) {
            $nextOpen = preg_match('/\[quote(\s[^\]]*)?\]/i', $text, $om, PREG_OFFSET_CAPTURE, $cursor) ? $om[0] : null;
            $nextClose = preg_match('/\[\/quote\]/i', $text, $cm, PREG_OFFSET_CAPTURE, $cursor) ? $cm[0] : null;

            if ($nextClose === null) {
                return null;
            }

            if ($nextOpen !== null && (int) $nextOpen[1] < (int) $nextClose[1]) {
                $depth++;
                $cursor = (int) $nextOpen[1] + strlen((string) $nextOpen[0]);
                continue;
            }

            $depth--;
            $closeStart = (int) $nextClose[1];
            $closeEnd = $closeStart + strlen((string) $nextClose[0]);

            if ($depth === 0) {
                return ['start' => $closeStart, 'end' => $closeEnd];
            }

            $cursor = $closeEnd;
        }

        return null;
    }

    private static function stripQuotes(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $first = $value[0];

        if (($first === "'" || $first === '"') && str_ends_with($value, $first)) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Remove sequências `"#` do displayname para não quebrar o parser do
     * flarum/mentions (que usa essa marca como delimitador).
     */
    private static function sanitizeDisplayName(string $name): string
    {
        return str_replace('"#', '_', $name);
    }

    private static function dropAttachmentTokens(string $text): string
    {
        return (string) preg_replace('/\[attachment=\d+\]/i', '', $text);
    }

    private static function rewriteVideos(string $text): string
    {
        return (string) preg_replace_callback(
            '/\[video=[^\]]*\](.*?)\[\/video\]/is',
            static function (array $m): string {
                $url = trim((string) ($m[1] ?? ''));
                return $url === '' ? '' : "\n" . $url . "\n";
            },
            $text
        );
    }

    private static function rewriteEmails(string $text): string
    {
        return (string) preg_replace_callback(
            '/\[email=([^\]]+)\](.*?)\[\/email\]/is',
            static function (array $m): string {
                $addr = trim((string) ($m[1] ?? ''));
                $addr = self::stripQuotes($addr);
                return $addr === '' ? '' : '[email]' . $addr . '[/email]';
            },
            $text
        );
    }
}
