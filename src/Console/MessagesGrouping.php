<?php

namespace Ramon\MybbMigrator\Console;

/**
 * Lógica pura da migração de mensagens privadas do MyBB para o flarum/messages.
 * Foi extraída do MigrateMessagesCommand para ser testável sem boot do Flarum e
 * sem acesso a banco, conforme §60.8 do playbook.
 *
 * Como o MyBB guarda uma PM: o datahandler (inc/datahandlers/pm.php) grava UMA
 * CÓPIA por dono — uma na pasta do destinatário (Inbox, folder=1, ou a pasta
 * para onde ele a moveu depois) e, se o remetente marcou "salvar cópia", outra
 * em Sent (folder=2) do remetente. Todas as cópias carregam o mesmo fromid,
 * dateline, subject, message e o mesmo `recipients` serializado. Por isso a
 * mensagem "canônica" é reconstruída agrupando as cópias, e cada cópia vira o
 * estado daquele dono (pasta, lido/não lido, quando leu).
 *
 * Como o flarum/messages organiza: uma conversa direta ('direct') é UMA por par
 * de pessoas — Dialog::for() faz firstOrCreate pelo par, sem assunto —, então
 * todas as PMs entre duas pessoas viram uma conversa só, em ordem cronológica.
 * Conversas com 3+ pessoas viram grupos do ernestdefoe/group-messages, que têm
 * nome; ali o assunto continua separando conversas, como no MyBB.
 */
final class MessagesGrouping
{
    /** Pastas fixas do MyBB (coluna privatemessages.folder). */
    public const FOLDER_INBOX = 1;
    public const FOLDER_SENT = 2;
    public const FOLDER_DRAFTS = 3;
    public const FOLDER_TRASH = 4;

    /** A partir daqui as pastas são as personalizadas de cada usuário. */
    public const FIRST_CUSTOM_FOLDER = 5;

    public const TYPE_DIRECT = 'direct';
    public const TYPE_GROUP = 'group';

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
     * Chave determinística que identifica unicamente uma conversa em grupo
     * (conjunto de participantes + assunto normalizado).
     *
     * @param list<int> $participantUids
     */
    public static function groupKey(array $participantUids, string $normalizedSubject): string
    {
        $sorted = self::sortedUnique($participantUids);

        $csv = implode(',', $sorted);

        return sha1($csv . '|' . $normalizedSubject);
    }

    /**
     * Chave da conversa direta entre duas pessoas — independe da ordem e do
     * assunto, porque o flarum/messages só tem uma conversa direta por par.
     */
    public static function directKey(int $a, int $b): string
    {
        return self::TYPE_DIRECT . ':' . min($a, $b) . ',' . max($a, $b);
    }

    /**
     * Para onde vai uma mensagem: a conversa dos participantes visíveis
     * (remetente + "to") e, para cada destinatário em cópia oculta, uma conversa
     * direta separada com o remetente.
     *
     * O BCC não entra na conversa principal de propósito: no MyBB quem está em
     * "to" nunca fica sabendo do BCC. Colocá-lo num grupo com todos exporia o
     * destinatário oculto — e o deixaria ver as respostas, que ele nunca viu.
     *
     * @param list<int> $to
     * @param list<int> $bcc
     * @return list<array{key: string, type: string, participants: list<int>}>
     */
    public static function conversationsOf(int $from, array $to, array $bcc, string $normalizedSubject): array
    {
        $to = array_values(array_diff(self::sortedUnique($to), [$from]));
        $bcc = array_values(array_diff(self::sortedUnique($bcc), [$from], $to));

        $out = [];

        if ($to !== []) {
            $participants = self::sortedUnique(array_merge([$from], $to));

            if (count($participants) === 2) {
                $out[] = [
                    'key'          => self::directKey($participants[0], $participants[1]),
                    'type'         => self::TYPE_DIRECT,
                    'participants' => $participants,
                ];
            } else {
                $out[] = [
                    'key'          => self::TYPE_GROUP . ':' . self::groupKey($participants, $normalizedSubject),
                    'type'         => self::TYPE_GROUP,
                    'participants' => $participants,
                ];
            }
        }

        foreach ($bcc as $uid) {
            $out[] = [
                'key'          => self::directKey($from, $uid),
                'type'         => self::TYPE_DIRECT,
                'participants' => self::sortedUnique([$from, $uid]),
            ];
        }

        return $out;
    }

    /**
     * Decodifica o campo `recipients` (PHP serialized) do MyBB:
     * a:N:{s:2:"to";a:M:{i:0;s:1:"X";...} s:3:"bcc";a:K:{...}}.
     *
     * Devolve null quando o campo está vazio ou corrompido (PMs muito antigas,
     * de antes do MyBB 1.4, não têm recipients) — o chamador cai então nos
     * donos das cópias e no toid, para nunca perder uma conversa por payload ruim.
     *
     * @return array{to: list<int>, bcc: list<int>}|null
     */
    public static function parseRecipients(string $raw): ?array
    {
        if (trim($raw) === '') {
            return null;
        }

        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        if (! is_array($decoded)) {
            return null;
        }

        $out = ['to' => [], 'bcc' => []];
        foreach (['to', 'bcc'] as $bucket) {
            if (! isset($decoded[$bucket]) || ! is_array($decoded[$bucket])) {
                continue;
            }
            foreach ($decoded[$bucket] as $value) {
                $uid = (int) $value;
                if ($uid > 0) {
                    $out[$bucket][] = $uid;
                }
            }
        }

        if ($out['to'] === [] && $out['bcc'] === []) {
            return null;
        }

        $out['to'] = self::sortedUnique($out['to']);
        $out['bcc'] = self::sortedUnique($out['bcc']);

        return $out;
    }

    /**
     * Lê as cópias de uma mensagem, no formato que o SELECT agrupado devolve:
     * "uid:folder:status:readtime:toid" separados por vírgula. Quando o mesmo
     * dono tem duas cópias (mandou para si mesmo junto com outros), vale a que
     * não está na lixeira — é a que ele ainda via.
     *
     * @return array<int, array{folder: int, status: int, readtime: int, toid: int}>
     */
    public static function parseCopies(string $concat): array
    {
        $copies = [];

        foreach (explode(',', $concat) as $chunk) {
            $parts = explode(':', trim($chunk));
            if (count($parts) < 4) {
                continue;
            }

            $uid = (int) $parts[0];
            if ($uid <= 0) {
                continue;
            }

            $copy = [
                'folder'   => (int) $parts[1],
                'status'   => (int) $parts[2],
                'readtime' => (int) $parts[3],
                'toid'     => (int) ($parts[4] ?? 0),
            ];

            if (! isset($copies[$uid]) || $copies[$uid]['folder'] === self::FOLDER_TRASH) {
                $copies[$uid] = $copy;
            }
        }

        return $copies;
    }

    /**
     * Pastas PERSONALIZADAS de um usuário a partir de `users.pmfolders`
     * ("id**nome$%%$id**nome..."). As fixas (0..4: Inbox, Unread, Sent, Drafts,
     * Trash) ficam de fora — no Flarum elas não são pastas, são a própria caixa.
     * O MyBB grava o nome com htmlspecialchars_uni, por isso o decode. Pasta sem
     * nome vira "Untitled folder", como o MyBB mostra.
     *
     * @return array<int, string> fid => nome, na ordem em que o usuário as tinha
     */
    public static function parsePmFolders(string $raw): array
    {
        $folders = [];

        foreach (explode('$%%$', $raw) as $entry) {
            if (! str_contains($entry, '**')) {
                continue;
            }

            [$fid, $name] = explode('**', $entry, 2);
            $fid = (int) $fid;

            if ($fid < self::FIRST_CUSTOM_FOLDER || isset($folders[$fid])) {
                continue;
            }

            $name = trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $folders[$fid] = $name !== '' ? $name : 'Untitled folder';
        }

        return $folders;
    }

    /**
     * Até onde um participante leu a conversa, no modelo do flarum/messages: um
     * único ponteiro (dialog_user.last_read_message_id) — tudo depois dele é não
     * lido. Por isso o ponteiro para logo antes da PRIMEIRA mensagem que ele não
     * leu no MyBB, mesmo que tenha lido outras depois (é o lado seguro: nada que
     * ele não viu aparece como visto).
     *
     * Conta como lida: mensagem que ele mesmo escreveu, cópia com status != 0
     * (lida/respondida/encaminhada), cópia na lixeira (ele já deu destino a ela)
     * e mensagem sem cópia dele (apagou de vez).
     *
     * @param list<array{from: int, dateline: int, copies: array<int, array{folder: int, status: int, readtime: int}>}> $messages
     * @return array{index: int, readAt: int} índice da última lida (-1 = nenhuma) e quando
     */
    public static function readState(array $messages, int $uid): array
    {
        $index = -1;
        $readAt = 0;

        foreach (array_values($messages) as $i => $message) {
            if ($message['from'] === $uid) {
                $readAt = max($readAt, $message['dateline']);
                $index = $i;
                continue;
            }

            $copy = $message['copies'][$uid] ?? null;

            if ($copy !== null && $copy['folder'] !== self::FOLDER_TRASH && $copy['status'] === 0) {
                break;
            }

            if ($copy !== null && $copy['readtime'] > 0) {
                $readAt = max($readAt, $copy['readtime']);
            }
            $index = $i;
        }

        return ['index' => $index, 'readAt' => $readAt];
    }

    /**
     * Onde o participante tinha a conversa guardada, no modelo do
     * ernestdefoe/pigeonhole (uma pasta e/ou "arquivada" por conversa, por
     * pessoa), a partir das pastas das cópias dele no MyBB (por mensagem).
     *
     *  - pasta: a pasta personalizada da cópia MAIS RECENTE que ele tinha numa
     *    pasta personalizada. No MyBB toda mensagem nova cai na Inbox, então a
     *    cópia mais recente quase sempre está lá mesmo numa conversa que o
     *    usuário organizou; mover algo para uma pasta, por outro lado, é sempre
     *    uma ação deliberada dele — é ela que vale.
     *  - arquivada: nenhuma cópia dele fora da lixeira (tudo na lixeira, ou já
     *    apagado de vez). A conversa não estava mais na caixa dele no MyBB; o
     *    arquivo tira da lista sem perder nada e volta sozinho com uma resposta.
     *
     * @param list<array{copies: array<int, array{folder: int}>}> $messages
     * @return array{folder: int|null, archived: bool}
     */
    public static function filing(array $messages, int $uid): array
    {
        $folder = null;
        $kept = 0;

        foreach ($messages as $message) {
            $copy = $message['copies'][$uid] ?? null;
            if ($copy === null || $copy['folder'] === self::FOLDER_TRASH) {
                continue;
            }

            $kept++;
            if ($copy['folder'] >= self::FIRST_CUSTOM_FOLDER) {
                $folder = $copy['folder'];
            }
        }

        return ['folder' => $folder, 'archived' => $kept === 0];
    }

    /**
     * @param list<int|string> $uids
     * @return list<int>
     */
    private static function sortedUnique(array $uids): array
    {
        $unique = array_values(array_unique(array_filter(array_map('intval', $uids), fn (int $uid) => $uid > 0)));
        sort($unique, SORT_NUMERIC);

        return $unique;
    }
}
