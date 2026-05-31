<?php

namespace Ramon\MybbMigrator\BBCode;

use Ramon\MybbMigrator\Support\Charset;
use Ramon\MybbMigrator\Support\TapatalkEmoji;

/**
 * Converte o BBCode de uma mensagem do MyBB para o formato esperado pelo
 * Flarum (flarum/bbcode + flarum/markdown + flarum/mentions). O pipeline:
 *  1. Repara mojibake (UTF-8 duplo) via Charset::fix.
 *  2. Normaliza quebras de linha.
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
 */
final class Converter
{
    public static function convert(string $rawMessage): string
    {
        $text = Charset::fix($rawMessage);
        $text = TapatalkEmoji::convert($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = self::rewriteQuotes($text);
        $text = self::dropAttachmentTokens($text);
        $text = self::rewriteVideos($text);
        $text = self::rewriteEmails($text);
        $text = self::normalizeBbcode($text);

        return $text;
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

        // [hr] / [hr/] → markdown thematic break.
        $text = (string) preg_replace('#\[hr\s*/?\]#i', "\n\n---\n\n", $text);

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
