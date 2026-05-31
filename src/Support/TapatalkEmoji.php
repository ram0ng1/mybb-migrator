<?php

namespace Ramon\MybbMigrator\Support;

/**
 * Converte os placeholders `[emojiN]` do Tapatalk (plugin mobile do MyBB) para
 * caracteres Unicode de emoji.
 *
 * IMPORTANTE: os índices `[emojiN]` do Tapatalk NÃO são um offset linear no
 * bloco Unicode — cada N mapeia para um emoji específico via tabela própria do
 * Tapatalk. A fórmula antiga (`0x1F600 + N`) produzia símbolos aleatórios para
 * N alto (ex.: `[emoji1317]` virava 🜥 em vez de 🙏). Use a tabela curada
 * `MAP`; códigos desconhecidos caem na fórmula legada apenas para preservar o
 * comportamento de migrações anteriores (e poderem ser re-corrigidos depois).
 */
final class TapatalkEmoji
{
    private const BASE = 0x1F600;
    private const CEIL = 0x1F9FF;

    /**
     * Tabela curada índice-Tapatalk → codepoint Unicode. Expanda conforme novos
     * códigos forem identificados (ou cole a tabela oficial do Tapatalk aqui).
     *
     * @var array<int, int>
     */
    public const MAP = [
        1317 => 0x1F64F, // 🙏 folded hands
    ];

    /**
     * Codepoint correto para um índice Tapatalk, ou null se desconhecido.
     */
    public static function codepointFor(int $n): ?int
    {
        return self::MAP[$n] ?? null;
    }

    /**
     * Char produzido pela fórmula ANTIGA (bugada) para um índice — usado pelo
     * comando de re-correção para localizar e substituir o caractere errado já
     * gravado nos posts migrados.
     */
    public static function legacyChar(int $n): string
    {
        $cp = self::BASE + $n;
        if ($cp > self::CEIL) {
            $cp = self::BASE + ($n % (self::CEIL - self::BASE + 1));
        }
        return mb_chr($cp, 'UTF-8') ?: '';
    }

    public static function convert(string $text): string
    {
        if (! str_contains($text, '[emoji')) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/\[emoji(\d{1,4})\]/',
            static function (array $m): string {
                $n = (int) $m[1];
                $cp = self::MAP[$n] ?? null;

                if ($cp === null) {
                    // Desconhecido: mantém a fórmula legada (re-corrigível depois).
                    return self::legacyChar($n);
                }

                return mb_chr($cp, 'UTF-8') ?: '';
            },
            $text
        );
    }
}
