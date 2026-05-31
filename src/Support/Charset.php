<?php

namespace Ramon\MybbMigrator\Support;

/**
 * Repara texto com UTF-8 duplamente codificado (mojibake), padrão deste
 * fórum MyBB: bytes UTF-8 originais foram lidos como Latin-1 e re-codificados
 * em UTF-8 (ex.: "á" -> "Ã¡"). A correção reverte exatamente uma camada e só
 * é aplicada quando o resultado é UTF-8 válido e a ida-e-volta reproduz a
 * entrada — texto já correto fica intacto.
 */
final class Charset
{
    public static function fix(?string $value): string
    {
        if ($value === null || $value === '') {
            return (string) $value;
        }

        $current = $value;

        for ($pass = 0; $pass < 3; $pass++) {
            $reverted = self::revertOneLayer($current);

            if ($reverted === null) {
                break;
            }

            $current = $reverted;
        }

        return $current;
    }

    /**
     * Tenta reverter uma camada de dupla codificação. Devolve null quando a
     * string não aparenta estar duplamente codificada (e deve ser mantida).
     */
    private static function revertOneLayer(string $string): ?string
    {
        $candidate = @mb_convert_encoding($string, 'Windows-1252', 'UTF-8');

        if ($candidate === false || $candidate === '' || ! mb_check_encoding($candidate, 'UTF-8')) {
            return null;
        }

        $roundTrip = mb_convert_encoding($candidate, 'UTF-8', 'Windows-1252');

        if ($roundTrip !== $string) {
            return null;
        }

        return $candidate;
    }

    /**
     * Heurística: presença das sequências típicas de mojibake (Â, Ã, â€ etc.).
     */
    private static function looksDoubleEncoded(string $string): bool
    {
        return (bool) preg_match('/[\x{00C2}\x{00C3}\x{0080}-\x{009F}][\x{0080}-\x{00BF}]/u', $string);
    }
}
