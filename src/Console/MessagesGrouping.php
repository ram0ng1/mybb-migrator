<?php

namespace Ramon\MybbMigrator\Console;

/**
 * Lógica pura de agrupamento de mensagens privadas do MyBB em conversas do
 * fof/byobu. Foi extraída do MigrateMessagesCommand para ser testável sem boot
 * do Flarum e sem acesso a banco, conforme §60.8 do playbook.
 *
 * Duas PMs pertencem à mesma conversa quando compartilham o mesmo conjunto de
 * participantes (independentemente da ordem) E o mesmo assunto normalizado
 * (sem prefixos "Re:" / "Fwd:" repetidos).
 */
final class MessagesGrouping
{
    /**
     * Remove repetições de "Re:", "RE:", "Fwd:", "FWD:" do início do assunto.
     * Case-insensitive, tolerante a espaços e a múltiplas camadas de prefixo.
     */
    public static function normalizeSubject(string $subject): string
    {
        $current = trim($subject);

        while (preg_match('/^\s*(re|fwd)\s*:\s*/i', $current) === 1) {
            $current = preg_replace('/^\s*(re|fwd)\s*:\s*/i', '', $current, 1) ?? $current;
        }

        return trim($current);
    }

    /**
     * Chave determinística que identifica unicamente uma conversa.
     *
     * @param list<int> $participantUids
     */
    public static function groupKey(array $participantUids, string $normalizedSubject): string
    {
        $sorted = array_values(array_unique(array_map('intval', $participantUids)));
        sort($sorted, SORT_NUMERIC);

        $csv = implode(',', $sorted);

        return sha1($csv . '|' . $normalizedSubject);
    }
}
