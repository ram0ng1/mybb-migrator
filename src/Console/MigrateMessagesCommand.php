<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\BBCode\Converter;
use Ramon\MybbMigrator\MybbDatabase;
use Ramon\MybbMigrator\Support\Charset;
use Symfony\Component\Console\Input\InputOption;

/**
 * Migra as mensagens privadas do MyBB (dfsmybb_privatemessages) para discussões
 * privadas do fof/byobu.
 *
 * Cada PM no MyBB aparece duplicada — uma cópia na pasta "Sent" do remetente
 * (folder=2) e outra na "Inbox" de cada destinatário (folder=1). Para evitar
 * processar a mesma mensagem N vezes consideramos apenas folder=2 como cópia
 * canônica; drafts (folder=3) e trash (folder=4) ficam de fora.
 *
 * As PMs são agrupadas em conversas pela combinação determinística de
 * (conjunto de participantes, assunto normalizado). Conversas com algum
 * participante ausente do users do Flarum são puladas (não há a quem atribuir
 * a discussão), e o contador de skips aparece no resumo final.
 */
class MigrateMessagesCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    /**
     * Folders do MyBB que representam cópias "Sent" — cada PM aparece uma única
     * vez aqui, evitando o trabalho de deduplicar entre múltiplas cópias inbox.
     */
    private const SOURCE_FOLDER = 2;

    /**
     * Tamanho dos batches de INSERT em posts/recipients/discussion_user. Mantém
     * o uso de memória controlado em conversas longas (algumas têm centenas
     * de mensagens).
     */
    private const BATCH_SIZE = 500;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:messages')
            ->setDescription('Migrate MyBB private messages to fof/byobu private discussions.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm the destructive execution.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Process at most N conversations.', null);

        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        $dryRun = (bool) $this->input->getOption('dry-run');
        $force  = (bool) $this->input->getOption('force');
        $limit  = $this->input->getOption('limit');
        $limit  = $limit === null ? null : max(1, (int) $limit);

        if (! $force && ! $dryRun) {
            $this->error('Destructive command. Run with --force (or --dry-run to only count).');
            return 1;
        }

        $mybb = $this->buildMybbDatabase($this->settings);

        if (! $dryRun) {
            $this->wipePreviousPrivateContent();
        }

        $this->info('[mybb:messages] Loading valid uids from Flarum...');
        $validUids = $this->loadValidUserIds();
        $this->info('[mybb:messages]   ' . count($validUids) . ' users available.');

        $this->info('[mybb:messages] Grouping MyBB PMs (folder=' . self::SOURCE_FOLDER . ')...');
        $conversations = $this->groupConversations($mybb, $validUids);
        $totalConversations = count($conversations);
        $this->info('[mybb:messages]   ' . $totalConversations . ' conversations detected.');

        if ($dryRun) {
            $totalPosts = 0;
            foreach ($conversations as $conv) {
                $totalPosts += count($conv['messages']);
            }
            $this->info("[mybb:messages] DRY-RUN: $totalConversations conversations, $totalPosts posts would be created.");
            return 0;
        }

        $created = 0;
        $skipped = 0;
        $postsCreated = 0;

        foreach ($conversations as $conv) {
            if ($limit !== null && $created >= $limit) {
                break;
            }

            $missing = $this->participantsMissing($conv['participants'], $validUids);
            if ($missing !== []) {
                $skipped++;
                continue;
            }

            $postsCreated += $this->persistConversation($mybb, $conv);
            $created++;

            if ($created % 100 === 0) {
                $this->info("[mybb:messages] $created conversations, $postsCreated posts");
            }
        }

        $this->info('[mybb:messages] Done.');
        $this->info("[mybb:messages]   conversations created: $created");
        $this->info("[mybb:messages]   posts created:    $postsCreated");
        $this->info("[mybb:messages]   conversations skipped (missing user): $skipped");

        return 0;
    }

    /**
     * Apaga conteúdo de uma migração anterior para garantir idempotência.
     * Só toca em discussões com is_private=1 e em recipients — discussões
     * públicas não são afetadas.
     */
    private function wipePreviousPrivateContent(): void
    {
        $this->info('[mybb:messages] Cleaning up content from a previous run...');
        $this->db->getSchemaBuilder()->disableForeignKeyConstraints();

        try {
            $privateIds = $this->db->table('discussions')->where('is_private', 1)->pluck('id')->all();

            if ($privateIds !== []) {
                foreach (array_chunk($privateIds, 1000) as $chunk) {
                    $this->db->table('posts')->whereIn('discussion_id', $chunk)->delete();
                    $this->db->table('discussion_user')->whereIn('discussion_id', $chunk)->delete();
                }
            }

            $this->db->table('recipients')->delete();

            if ($privateIds !== []) {
                foreach (array_chunk($privateIds, 1000) as $chunk) {
                    $this->db->table('discussions')->whereIn('id', $chunk)->delete();
                }
            }
        } finally {
            $this->db->getSchemaBuilder()->enableForeignKeyConstraints();
        }
    }

    /**
     * Lê todos os ids da tabela users do Flarum para podermos validar que cada
     * participante de uma PM existe antes de tentar criar a recipient/post.
     *
     * @return array<int, true>
     */
    private function loadValidUserIds(): array
    {
        $ids = [];
        foreach ($this->db->table('users')->select('id')->cursor() as $row) {
            $ids[(int) $row->id] = true;
        }
        return $ids;
    }

    /**
     * Agrupa as PMs em conversas. Cada item retornado tem o conjunto de
     * participantes, o título, o primeiro/último dateline e a lista de
     * mensagens já ordenada por dateline.
     *
     * @param array<int, true> $validUids
     * @return list<array{
     *     key: string,
     *     participants: list<int>,
     *     title: string,
     *     firstDateline: int,
     *     lastDateline: int,
     *     starter: int,
     *     lastAuthor: int,
     *     messages: list<array{pmid:int, from:int, dateline:int}>,
     * }>
     */
    private function groupConversations(MybbDatabase $mybb, array $validUids): array
    {
        $table = $mybb->table('privatemessages');
        // Não selecionamos `message`/`ipaddress` aqui: o corpo (campo pesado) é
        // adiado para persistConversation() e buscado em lote por pmid. Assim o
        // agrupamento — que precisa ver todas as PMs de uma vez — guarda só
        // metadados leves, mantendo a memória controlada em fóruns grandes.
        $sql = "SELECT pmid, fromid, recipients, subject, dateline
                FROM $table
                WHERE folder = " . self::SOURCE_FOLDER . "
                ORDER BY dateline ASC, pmid ASC";

        /** @var array<string, array{
         *     key: string, participants: list<int>, title: string,
         *     firstDateline: int, lastDateline: int, starter: int, lastAuthor: int,
         *     messages: list<array{pmid:int, from:int, dateline:int}>,
         * }> $groups */
        $groups = [];

        foreach ($mybb->cursor($sql) as $row) {
            $fromid    = (int) $row['fromid'];
            $subject   = Charset::fix((string) $row['subject']);
            $dateline  = (int) $row['dateline'];
            $pmid      = (int) $row['pmid'];

            if ($fromid <= 0 || ! isset($validUids[$fromid])) {
                continue;
            }

            $recipientUids = $this->extractRecipientUids((string) $row['recipients']);
            $participants  = $this->mergeParticipants($fromid, $recipientUids);

            if (count($participants) < 2) {
                continue;
            }

            $normalized = MessagesGrouping::normalizeSubject($subject);
            $key        = MessagesGrouping::groupKey($participants, $normalized);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key'           => $key,
                    'participants'  => $participants,
                    'title'         => $subject !== '' ? $subject : '(no subject)',
                    'firstDateline' => $dateline,
                    'lastDateline'  => $dateline,
                    'starter'       => $fromid,
                    'lastAuthor'    => $fromid,
                    'messages'      => [],
                ];
            }

            $groups[$key]['messages'][] = [
                'pmid'     => $pmid,
                'from'     => $fromid,
                'dateline' => $dateline,
            ];
            $groups[$key]['lastDateline'] = $dateline;
            $groups[$key]['lastAuthor']   = $fromid;
        }

        return array_values($groups);
    }

    /**
     * Decodifica o campo recipients (PHP serialized) do MyBB. Em formato
     * canônico recebe-se a:N:{s:2:"to";a:M:{i:0;s:1:"X";...}; s:3:"bcc";...}.
     * Se a desserialização falhar ou retornar shape inesperado caímos no toid
     * — assim nunca perdemos uma conversa por causa de payload corrompido.
     *
     * @return list<int>
     */
    private function extractRecipientUids(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        if (! is_array($decoded)) {
            return [];
        }

        $uids = [];
        foreach (['to', 'bcc'] as $bucket) {
            if (! isset($decoded[$bucket]) || ! is_array($decoded[$bucket])) {
                continue;
            }
            foreach ($decoded[$bucket] as $value) {
                $uid = (int) $value;
                if ($uid > 0) {
                    $uids[] = $uid;
                }
            }
        }

        return $uids;
    }

    /**
     * Une o autor + destinatários e devolve a lista ordenada de uids únicos
     * usada como conjunto de participantes da conversa.
     *
     * @param list<int> $recipientUids
     * @return list<int>
     */
    private function mergeParticipants(int $fromid, array $recipientUids): array
    {
        $all = array_merge([$fromid], $recipientUids);
        $unique = array_values(array_unique(array_map('intval', $all)));
        sort($unique, SORT_NUMERIC);

        return $unique;
    }

    /**
     * Lista os participantes que não existem no users do Flarum — usada para
     * decidir se a conversa deve ser pulada.
     *
     * @param list<int> $participants
     * @param array<int, true> $validUids
     * @return list<int>
     */
    private function participantsMissing(array $participants, array $validUids): array
    {
        $missing = [];
        foreach ($participants as $uid) {
            if (! isset($validUids[$uid])) {
                $missing[] = $uid;
            }
        }
        return $missing;
    }

    /**
     * Cria discussão + recipients + posts + discussion_user para uma conversa,
     * tudo dentro de uma única transação. Retorna a quantidade de posts criada.
     *
     * @param array{
     *     participants: list<int>, title: string, firstDateline: int,
     *     lastDateline: int, starter: int, lastAuthor: int,
     *     messages: list<array{pmid:int, from:int, dateline:int}>,
     * } $conv
     */
    private function persistConversation(MybbDatabase $mybb, array $conv): int
    {
        $count = 0;

        $this->db->transaction(function () use ($mybb, $conv, &$count): void {
            $createdAt = $this->ts($conv['firstDateline']);
            $lastAt    = $this->ts($conv['lastDateline']);
            $title     = $this->truncate(Charset::fix($conv['title']), 200);
            $messages  = $conv['messages'];
            $msgCount  = count($messages);

            $discussionId = (int) $this->db->table('discussions')->insertGetId([
                'title'               => $title,
                'comment_count'       => $msgCount,
                'participant_count'   => count($conv['participants']),
                'created_at'          => $createdAt,
                'user_id'             => $conv['starter'],
                'last_posted_at'      => $lastAt,
                'last_posted_user_id' => $conv['lastAuthor'],
                'slug'                => '',
                'is_private'          => 1,
                'is_sticky'           => 0,
                'is_locked'           => 0,
            ]);

            $this->db->table('discussions')->where('id', $discussionId)
                ->update(['slug' => (string) $discussionId]);

            $recipients = [];
            foreach ($conv['participants'] as $uid) {
                $recipients[] = [
                    'discussion_id' => $discussionId,
                    'user_id'       => $uid,
                    'group_id'      => null,
                    'created_at'    => $createdAt,
                    'updated_at'    => $createdAt,
                    'removed_at'    => null,
                ];
            }
            foreach (array_chunk($recipients, self::BATCH_SIZE) as $chunk) {
                $this->db->table('recipients')->insert($chunk);
            }

            $discussionUserRows = [];
            foreach ($conv['participants'] as $uid) {
                $discussionUserRows[] = [
                    'user_id'               => $uid,
                    'discussion_id'         => $discussionId,
                    'last_read_at'          => null,
                    'last_read_post_number' => null,
                    'subscription'          => null,
                ];
            }
            foreach (array_chunk($discussionUserRows, self::BATCH_SIZE) as $chunk) {
                $this->db->table('discussion_user')->insert($chunk);
            }

            $firstPostId = null;
            $lastPostId  = null;
            $lastNumber  = 0;
            $number      = 0;

            foreach (array_chunk($messages, self::BATCH_SIZE) as $chunk) {
                $bodies = $this->fetchMessageBodies($mybb, array_column($chunk, 'pmid'));

                $rows = [];
                foreach ($chunk as $msg) {
                    $number++;
                    $body = $bodies[$msg['pmid']] ?? ['message' => '', 'ipaddress' => ''];
                    $rows[] = [
                        'discussion_id' => $discussionId,
                        'number'        => $number,
                        'created_at'    => $this->ts($msg['dateline']),
                        'user_id'       => $msg['from'],
                        'type'          => 'comment',
                        'content'       => Converter::convert($body['message']),
                        'ip_address'    => $body['ipaddress'] !== '' ? $this->truncate($body['ipaddress'], 45) : null,
                        'is_private'    => 0,
                    ];
                }

                $this->db->table('posts')->insert($rows);

                if ($firstPostId === null) {
                    $firstNumber = $number - count($rows) + 1;
                    $firstPostId = (int) $this->db->table('posts')
                        ->where('discussion_id', $discussionId)
                        ->where('number', $firstNumber)
                        ->value('id');
                }
                $lastPostId = (int) $this->db->table('posts')
                    ->where('discussion_id', $discussionId)
                    ->where('number', $number)
                    ->value('id');
                $lastNumber = $number;
                $count += count($rows);
            }

            $this->db->table('discussions')->where('id', $discussionId)->update([
                'first_post_id'    => $firstPostId,
                'last_post_id'     => $lastPostId,
                'last_post_number' => $lastNumber,
            ]);
        });

        return $count;
    }

    /**
     * Busca os corpos das PMs (campo pesado, adiado durante o agrupamento) por
     * pmid, em lote. Devolve um mapa pmid => ['message' => texto já corrigido,
     * 'ipaddress' => ip legível]. Adiar o corpo mantém o agrupamento leve mesmo
     * em fóruns com centenas de milhares de mensagens.
     *
     * @param list<int> $pmids
     * @return array<int, array{message: string, ipaddress: string}>
     */
    private function fetchMessageBodies(MybbDatabase $mybb, array $pmids): array
    {
        if ($pmids === []) {
            return [];
        }

        $table        = $mybb->table('privatemessages');
        $placeholders = implode(',', array_fill(0, count($pmids), '?'));
        $stmt         = $mybb->select(
            "SELECT pmid, message, ipaddress FROM $table WHERE pmid IN ($placeholders)",
            $pmids
        );

        $map = [];
        foreach ($stmt as $row) {
            $map[(int) $row['pmid']] = [
                'message'   => Charset::fix((string) $row['message']),
                'ipaddress' => $this->normalizeIp((string) $row['ipaddress']),
            ];
        }

        return $map;
    }

    /**
     * Converte o IP varbinary do MyBB para texto. Aceita formatos binário
     * (INET_PTON) e textual já legível; devolve string vazia quando não há IP.
     */
    private function normalizeIp(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $length = strlen($raw);
        if ($length === 4 || $length === 16) {
            $text = @inet_ntop($raw);
            if (is_string($text)) {
                return $text;
            }
        }

        return $raw;
    }

    /**
     * Converte unix timestamp em string datetime MySQL.
     */
    private function ts(int $unix): string
    {
        if ($unix <= 0) {
            $unix = time();
        }

        return gmdate('Y-m-d H:i:s', $unix);
    }

    /**
     * Trunca uma string respeitando UTF-8, sem cortar caracteres multibyte
     * pela metade.
     */
    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value, 'UTF-8') <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max, 'UTF-8');
    }
}
