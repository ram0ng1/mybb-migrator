<?php

namespace Ramon\MybbMigrator\Console;

/**
 * Decodifica a coluna `options`/`votes` da tabela `mybb_polls`. O MyBB 1.8
 * armazena as opções como uma string única separada pelo delimitador literal
 * `||~|~||`; algumas distribuições antigas (ou plugins) usam PHP `serialize()`
 * de um array indexado. O parser cobre ambos os formatos: tenta `unserialize`
 * primeiro e, em caso de falha, faz split pelo separador padrão. Entrada
 * inválida devolve array vazio em vez de lançar.
 */
final class PollOptionsParser
{
    private const SEPARATOR = '||~|~||';

    /**
     * @return array<int, string>
     */
    public static function parse(string $serialized): array
    {
        if ($serialized === '') {
            return [];
        }

        $decoded = @unserialize($serialized, ['allowed_classes' => false]);

        if ($decoded === false && $serialized !== 'b:0;') {
            $decoded = null;
        }

        if (is_array($decoded)) {
            return self::normalize($decoded);
        }

        if ($decoded !== null && ! is_array($decoded)) {
            return [];
        }

        $parts = explode(self::SEPARATOR, $serialized);

        return self::normalize($parts);
    }

    /**
     * @param array<int|string, mixed> $items
     * @return array<int, string>
     */
    private static function normalize(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $trimmed = trim((string) $item);
            if ($trimmed === '') {
                continue;
            }
            $out[] = $trimmed;
        }

        return $out;
    }
}
