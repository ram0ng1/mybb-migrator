<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Extension\ExtensionManager;
use Flarum\Formatter\Formatter;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\BBCode\Converter;
use Ramon\MybbMigrator\MybbDatabase;
use Ramon\MybbMigrator\Support\Charset;
use s9e\TextFormatter\Utils;
use Symfony\Component\Console\Input\InputOption;

/**
 * Migra as mensagens privadas do MyBB ({prefix}privatemessages) para o
 * flarum/messages — conversas diretas — e, com as extensões ativas, para os
 * grupos do ernestdefoe/group-messages e as pastas/arquivo do
 * ernestdefoe/pigeonhole.
 *
 * De onde vem cada coisa (ver MessagesGrouping para a regra de cada uma):
 *  - MENSAGEM: o MyBB grava uma cópia por dono (Inbox do destinatário, Sent do
 *    remetente, ou a pasta para onde cada um a moveu). As cópias são agrupadas
 *    de volta numa mensagem só por (fromid, dateline, subject, message,
 *    recipients). Antes só a cópia Sent (folder=2) era lida, e toda PM enviada
 *    sem "salvar cópia" — ou cuja cópia o remetente apagou — se perdia.
 *  - CONVERSA: duas pessoas = conversa direta (uma por par, como o
 *    flarum/messages exige); três ou mais = grupo, separado por assunto. O
 *    assunto das conversas diretas é preservado como uma linha em negrito no
 *    topo da mensagem que abre cada assunto (desligue com --no-subject-header).
 *  - LEITURA: o estado da cópia de cada participante (status/readtime) vira o
 *    ponteiro last_read_message_id do dialog_user.
 *  - PASTAS: as pastas personalizadas de users.pmfolders viram pastas do
 *    pigeonhole; a conversa vai para a pasta onde o usuário tinha guardado a
 *    mensagem mais recente dela, e conversas que só existiam na lixeira dele
 *    vão para o arquivo.
 *
 * O que NÃO vira conversa (e aparece no resumo): rascunhos (folder=3 — não há
 * rascunho de mensagem no flarum/messages e eles nunca foram enviados), PMs para
 * si mesmo, grupos sem o group-messages ativo e conversas sem nenhum
 * participante existente no Flarum.
 *
 * Re-executar é seguro: antes de gravar, apaga só as conversas que ESTE comando
 * criou (tabela mybb_migrated_dialogs) e que ninguém tocou depois. Conversas
 * criadas por membros no Flarum nunca são lidas nem apagadas.
 *
 * A limpeza das discussões privadas que a versão anterior deste comando criou
 * no fof/byobu é o comando separado `mybb:messages-drop-byobu`.
 */
class MigrateMessagesCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    /**
     * Tamanho dos batches de INSERT/SELECT. Mantém o uso de memória controlado
     * em conversas longas (algumas têm centenas de mensagens).
     */
    private const BATCH_SIZE = 500;

    /** Limites das colunas de destino. */
    private const GROUP_TITLE_MAX = 150;   // group_dialogs.title
    private const FOLDER_NAME_MAX = 50;    // pigeonhole_folders.name
    private const FOLDERS_PER_USER = 50;   // Folder::MAX do pigeonhole

    /** @var array<string, bool> */
    private array $hasTableCache = [];

    /** @var array<string, int> */
    private array $stats = [];

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected ExtensionManager $extensions,
        protected Formatter $formatter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:messages')
            ->setDescription('Migrate MyBB private messages to flarum/messages (+ group-messages groups and pigeonhole folders when enabled).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm the execution (re-runs replace only what this command created).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Create at most N conversations.', null)
            ->addOption('skip-missing-users', null, InputOption::VALUE_NONE, 'Skip a whole conversation when any participant is missing from Flarum (default: keep it for the members that exist, with the missing author shown as a deleted user).')
            ->addOption('no-subject-header', null, InputOption::VALUE_NONE, 'Do not prepend the MyBB subject (in bold) to the message that opens each subject of a direct conversation.');

        $this->addMybbConnectionOptions();
    }

    protected function fire(): int
    {
        $dryRun = (bool) $this->input->getOption('dry-run');
        $force  = (bool) $this->input->getOption('force');
        $limit  = $this->input->getOption('limit');
        $limit  = $limit === null ? null : max(1, (int) $limit);
        $skipMissing   = (bool) $this->input->getOption('skip-missing-users');
        $subjectHeader = ! $this->input->getOption('no-subject-header');

        if (! $force && ! $dryRun) {
            $this->error('Run with --force (or --dry-run to only count).');
            return 1;
        }

        if (! $this->extensions->isEnabled('flarum-messages') || ! $this->hasTable('dialogs')) {
            $this->error('flarum/messages is not enabled (table dialogs missing). Enable it and run `php flarum migrate` first.');
            return 1;
        }

        if (! $this->hasTable('mybb_migrated_dialogs')) {
            $this->error('Table mybb_migrated_dialogs is missing — run `php flarum migrate`.');
            return 1;
        }

        $groups     = $this->extensions->isEnabled('ernestdefoe-group-messages') && $this->hasTable('group_dialogs');
        $pigeonhole = $this->extensions->isEnabled('ernestdefoe-pigeonhole') && $this->hasTable('pigeonhole_folders')
            && $this->hasTable('pigeonhole_filings') && $this->hasTable('mybb_migrated_pm_folders');

        $this->info('[mybb:messages] group-messages: ' . ($groups ? 'enabled' : 'NOT enabled — 3+ participant conversations will be skipped'));
        $this->info('[mybb:messages] pigeonhole:     ' . ($pigeonhole ? 'enabled' : 'NOT enabled — folders/archive will not be migrated'));

        $mybb = $this->buildMybbDatabase($this->settings);

        $this->info('[mybb:messages] Loading valid uids from Flarum...');
        $validUids = $this->loadValidUserIds();
        $this->info('[mybb:messages]   ' . count($validUids) . ' users available.');

        $this->info('[mybb:messages] Rebuilding MyBB PMs from their per-owner copies...');
        $conversations = $this->groupConversations($mybb, $validUids);
        $this->stats['drafts ignored'] = (int) $mybb->scalar(
            'SELECT COUNT(*) FROM ' . $mybb->table('privatemessages') . ' WHERE folder = ' . MessagesGrouping::FOLDER_DRAFTS
        );
        $this->info('[mybb:messages]   ' . count($conversations) . ' conversations detected.');

        $folders = $pigeonhole ? $this->loadCustomFolders($mybb, $validUids) : [];

        if ($dryRun) {
            return $this->dryRunReport($conversations, $validUids, $groups, $pigeonhole, $folders, $skipMissing, $limit);
        }

        $preserved = $this->wipePreviousMigration();

        $folderMap = $pigeonhole ? $this->ensureFolders($folders) : [];
        $replyAnyone = $this->usersWhoMessageWithoutPermission();

        $created = 0;
        $messagesCreated = 0;

        foreach ($conversations as $conv) {
            if ($limit !== null && $created >= $limit) {
                break;
            }

            if (isset($preserved[$conv['key']])) {
                $this->bump('conversations kept (touched in Flarum)');
                continue;
            }

            $members = $this->membersOf($conv, $validUids, $groups, $skipMissing);
            if ($members === null) {
                continue;
            }

            $messagesCreated += $this->persistConversation(
                $mybb, $conv, $members, $validUids, $folderMap, $replyAnyone, $pigeonhole, $subjectHeader
            );
            $created++;

            if ($created % 100 === 0) {
                $this->info("[mybb:messages] $created conversations, $messagesCreated messages");
            }
        }

        $this->info('[mybb:messages] Done.');
        $this->info("  conversations created: $created");
        $this->info("  messages created: $messagesCreated");
        $this->printStats();

        return 0;
    }

    /**
     * Quem fica como membro da conversa no Flarum, ou null quando ela é pulada
     * (e o motivo entra no resumo).
     *
     * Participante ausente no Flarum (conta apagada antes da migração): por
     * padrão a conversa é mantida para quem existe, e as mensagens dele ficam
     * sem autor — exatamente o que o flarum/messages faz quando um membro apaga
     * a conta (DialogServiceProvider põe user_id = null), e o que o MyBB fazia
     * (a cópia do destinatário sobrevivia ao remetente). --skip-missing-users
     * volta ao comportamento antigo: pula a conversa inteira.
     *
     * @param array{type: string, participants: list<int>} $conv
     * @param array<int, true> $validUids
     * @return list<int>|null
     */
    private function membersOf(array $conv, array $validUids, bool $groups, bool $skipMissing): ?array
    {
        if ($conv['type'] === MessagesGrouping::TYPE_GROUP && ! $groups) {
            $this->bump('groups skipped (group-messages off)');
            return null;
        }

        $members = array_values(array_filter($conv['participants'], fn (int $uid) => isset($validUids[$uid])));

        if ($members === []) {
            $this->bump('conversations skipped (no member in Flarum)');
            return null;
        }

        if (count($members) < count($conv['participants'])) {
            if ($skipMissing) {
                $this->bump('conversations skipped (missing user)');
                return null;
            }
            $this->bump('conversations with a missing user');
        }

        return $members;
    }

    /**
     * @param list<array<string, mixed>> $conversations
     * @param array<int, true> $validUids
     * @param array<int, array<int, string>> $folders
     */
    private function dryRunReport(array $conversations, array $validUids, bool $groups, bool $pigeonhole, array $folders, bool $skipMissing, ?int $limit): int
    {
        $count = 0;
        $messages = 0;
        $byType = [MessagesGrouping::TYPE_DIRECT => 0, MessagesGrouping::TYPE_GROUP => 0];
        $previous = $this->previousMigration();

        foreach ($conversations as $conv) {
            if ($limit !== null && $count >= $limit) {
                break;
            }
            if (isset($previous['preserved'][$conv['key']])) {
                $this->bump('conversations kept (touched in Flarum)');
                continue;
            }
            if ($this->membersOf($conv, $validUids, $groups, $skipMissing) === null) {
                continue;
            }
            $count++;
            $byType[$conv['type']]++;
            $messages += count($conv['messages']);
        }

        $this->info('[mybb:messages] DRY-RUN — nothing written.');
        $this->info("  conversations to create: $count");
        $this->info('  direct conversations: ' . $byType[MessagesGrouping::TYPE_DIRECT]);
        $this->info('  group conversations: ' . $byType[MessagesGrouping::TYPE_GROUP]);
        $this->info("  messages to create: $messages");
        if ($pigeonhole) {
            $this->info('  MyBB custom folders: ' . array_sum(array_map('count', $folders)));
        }
        $this->info('  previous run, to replace: ' . count($previous['delete']));
        $this->printStats();

        return 0;
    }

    /**
     * O que uma execução anterior DESTE comando deixou, sem alterar nada: as
     * conversas listadas em mybb_migrated_dialogs que podem ser recriadas, as
     * que alguém usou no Flarum depois da migração (mensagem nova — id além do
     * último migrado —, reação ou resposta do group-messages), que ficam como
     * estão e não são recriadas, e as linhas de mapa cuja conversa já não existe.
     *
     * @return array{preserved: array<string, true>, delete: list<int>, orphans: list<int>}
     */
    private function previousMigration(): array
    {
        $out = ['preserved' => [], 'delete' => [], 'orphans' => []];

        $rows = $this->db->table('mybb_migrated_dialogs as m')
            ->leftJoin('dialogs as d', 'd.id', '=', 'm.dialog_id')
            ->select('m.dialog_id', 'm.conversation_key', 'm.last_message_id as mapped_last', 'd.id as existing', 'd.last_message_id as current_last')
            ->get();

        foreach ($rows as $row) {
            if ($row->existing === null) {
                $out['orphans'][] = (int) $row->dialog_id;
            } elseif ($this->touchedSinceMigration((int) $row->dialog_id, (int) $row->mapped_last, $row->current_last === null ? null : (int) $row->current_last)) {
                $out['preserved'][(string) $row->conversation_key] = true;
            } else {
                $out['delete'][] = (int) $row->dialog_id;
            }
        }

        return $out;
    }

    /**
     * Apaga o que uma execução anterior DESTE comando criou, para que rodar de
     * novo não duplique nada (ver previousMigration() para o que fica).
     *
     * Os filhos são apagados explicitamente em vez de confiar só no CASCADE: em
     * instalações migradas por dump as FKs nem sempre existem.
     *
     * @return array<string, true> chaves de conversa preservadas
     */
    private function wipePreviousMigration(): array
    {
        $this->info('[mybb:messages] Removing conversations created by a previous run...');

        $previous = $this->previousMigration();

        foreach (array_chunk($previous['orphans'], 1000) as $chunk) {
            $this->db->table('mybb_migrated_dialogs')->whereIn('dialog_id', $chunk)->delete();
        }

        foreach (array_chunk($previous['delete'], 200) as $chunk) {
            $this->deleteDialogs($chunk);
        }

        $this->info('[mybb:messages]   removed: ' . count($previous['delete']) . ', kept (used in Flarum since): ' . count($previous['preserved']));

        return $previous['preserved'];
    }

    private function touchedSinceMigration(int $dialogId, int $mappedLast, ?int $currentLast): bool
    {
        if ($currentLast !== $mappedLast) {
            return true;
        }

        if ($this->db->table('dialog_messages')->where('dialog_id', $dialogId)->where('id', '>', $mappedLast)->exists()) {
            return true;
        }

        foreach (['dialog_message_reactions' => 'message_id', 'dialog_message_replies' => 'message_id'] as $table => $column) {
            if ($this->hasTable($table) && $this->db->table($table)
                ->whereIn($column, fn ($q) => $q->select('id')->from('dialog_messages')->where('dialog_id', $dialogId))
                ->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $dialogIds
     */
    private function deleteDialogs(array $dialogIds): void
    {
        $this->db->transaction(function () use ($dialogIds): void {
            $messageIds = $this->db->table('dialog_messages')->whereIn('dialog_id', $dialogIds)->pluck('id')->all();

            foreach (array_chunk($messageIds, 1000) as $chunk) {
                foreach ([
                    'dialog_message_mentions_user', 'dialog_message_mentions_post',
                    'dialog_message_mentions_group', 'dialog_message_mentions_tag',
                ] as $table) {
                    if ($this->hasTable($table)) {
                        $this->db->table($table)->whereIn('dialog_message_id', $chunk)->delete();
                    }
                }
                foreach (['dialog_message_reactions' => 'message_id', 'dialog_message_replies' => 'message_id'] as $table => $column) {
                    if ($this->hasTable($table)) {
                        $this->db->table($table)->whereIn($column, $chunk)->delete();
                    }
                }
                if ($this->hasTable('dialog_message_replies')) {
                    $this->db->table('dialog_message_replies')->whereIn('reply_to_id', $chunk)->delete();
                }
            }

            foreach (['pigeonhole_filings', 'group_dialog_moderators', 'group_dialogs'] as $table) {
                if ($this->hasTable($table)) {
                    $this->db->table($table)->whereIn('dialog_id', $dialogIds)->delete();
                }
            }

            $this->db->table('dialog_user')->whereIn('dialog_id', $dialogIds)->delete();
            // Solta os ponteiros antes de apagar as mensagens (FK de dialogs para
            // dialog_messages), sem depender do SET NULL da FK.
            $this->db->table('dialogs')->whereIn('id', $dialogIds)->update(['first_message_id' => null, 'last_message_id' => null]);
            $this->db->table('dialog_messages')->whereIn('dialog_id', $dialogIds)->delete();
            $this->db->table('dialogs')->whereIn('id', $dialogIds)->delete();
            $this->db->table('mybb_migrated_dialogs')->whereIn('dialog_id', $dialogIds)->delete();
        });
    }

    /**
     * Lê todos os ids da tabela users do Flarum. O migrador preserva uid = id,
     * então um uid do MyBB existe no Flarum se e só se este id existe.
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
     * Reconstrói as mensagens a partir das cópias e as distribui em conversas.
     *
     * O agrupamento das cópias é feito pelo próprio MySQL (GROUP BY nos campos
     * que todas as cópias de uma mesma PM compartilham), devolvendo uma linha por
     * mensagem com o estado de cada cópia num GROUP_CONCAT. O corpo (campo
     * pesado) fica para persistConversation(), buscado em lote pelo menor pmid.
     *
     * O `recipients` entra na chave para não fundir o "mass mail" do MyBB: o
     * mesmo texto, no mesmo segundo, enviado em PMs separadas para cada membro.
     *
     * @param array<int, true> $validUids
     * @return list<array{
     *     key: string, type: string, participants: list<int>, title: string,
     *     firstDateline: int, lastDateline: int,
     *     messages: list<array{pmid: int, from: int, dateline: int, subject: string, copies: array<int, array{folder: int, status: int, readtime: int, toid: int}>}>,
     * }>
     */
    private function groupConversations(MybbDatabase $mybb, array $validUids): array
    {
        $table = $mybb->table('privatemessages');

        // Uma PM para 200 pessoas tem 200 cópias; o limite padrão (1024 bytes)
        // cortaria o GROUP_CONCAT em silêncio.
        $mybb->pdo()->exec('SET SESSION group_concat_max_len = 16777216');

        $sql = "SELECT MIN(pmid) AS pmid, fromid, dateline, MIN(subject) AS subject, MIN(recipients) AS recipients,
                       GROUP_CONCAT(CONCAT_WS(':', uid, folder, status, readtime, toid) ORDER BY pmid SEPARATOR ',') AS copies
                FROM $table
                WHERE folder <> " . MessagesGrouping::FOLDER_DRAFTS . "
                GROUP BY fromid, dateline, MD5(subject), MD5(message), MD5(recipients)
                ORDER BY dateline ASC, MIN(pmid) ASC";

        $groups = [];

        foreach ($mybb->cursor($sql) as $row) {
            $from     = (int) $row['fromid'];
            $subject  = Charset::fix((string) $row['subject']);
            $dateline = (int) $row['dateline'];
            $copies   = MessagesGrouping::parseCopies((string) $row['copies']);

            if ($from <= 0) {
                $this->bump('messages without sender');
                continue;
            }

            $recipients = MessagesGrouping::parseRecipients((string) $row['recipients']);
            if ($recipients === null) {
                // PM antiga sem recipients: os destinatários são os donos das
                // cópias e os toid gravados nelas.
                $to = [];
                foreach ($copies as $uid => $copy) {
                    $to[] = $uid;
                    $to[] = $copy['toid'];
                }
                $recipients = ['to' => $to, 'bcc' => []];
            }

            $normalized = MessagesGrouping::normalizeSubject($subject);
            $targets = MessagesGrouping::conversationsOf($from, $recipients['to'], $recipients['bcc'], $normalized);

            if ($targets === []) {
                $this->bump('messages to self ignored');
                continue;
            }

            if (! isset($validUids[$from])) {
                $this->bump('messages by a missing user');
            }

            foreach ($targets as $target) {
                $key = $target['key'];

                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'key'           => $key,
                        'type'          => $target['type'],
                        'participants'  => $target['participants'],
                        'title'         => $normalized !== '' ? $normalized : $subject,
                        'firstDateline' => $dateline,
                        'lastDateline'  => $dateline,
                        'messages'      => [],
                    ];
                }

                $groups[$key]['messages'][] = [
                    'pmid'     => (int) $row['pmid'],
                    'from'     => $from,
                    'dateline' => $dateline,
                    'subject'  => $subject,
                    // Só as cópias de quem está NESTA conversa: a cópia do BCC
                    // não pode mexer no estado da conversa principal.
                    'copies'   => array_intersect_key($copies, array_flip($target['participants'])),
                ];
                $groups[$key]['lastDateline'] = $dateline;
            }
        }

        return array_values($groups);
    }

    /**
     * Pastas personalizadas (fid >= 5) de cada usuário existente no Flarum.
     *
     * @param array<int, true> $validUids
     * @return array<int, array<int, string>> uid => [fid => nome]
     */
    private function loadCustomFolders(MybbDatabase $mybb, array $validUids): array
    {
        $out = [];
        $sql = 'SELECT uid, pmfolders FROM ' . $mybb->table('users') . " WHERE pmfolders <> ''";

        foreach ($mybb->cursor($sql) as $row) {
            $uid = (int) $row['uid'];
            if (! isset($validUids[$uid])) {
                continue;
            }

            $folders = MessagesGrouping::parsePmFolders(Charset::fix((string) $row['pmfolders']));
            if ($folders !== []) {
                $out[$uid] = array_slice($folders, 0, self::FOLDERS_PER_USER, true);
            }
        }

        return $out;
    }

    /**
     * Cria (ou reusa, numa re-execução) as pastas do pigeonhole. A pasta já
     * criada por uma execução anterior é reaproveitada pelo par (usuário, fid
     * do MyBB): apagá-la desarquivaria também as conversas que o membro filou
     * nela à mão depois da migração. Novas pastas entram depois das que o membro
     * já tem, na ordem em que estavam no MyBB.
     *
     * @param array<int, array<int, string>> $folders
     * @return array<int, array<int, int>> uid => [fid do MyBB => pigeonhole_folders.id]
     */
    private function ensureFolders(array $folders): array
    {
        $map = [];

        $existing = $this->db->table('mybb_migrated_pm_folders as m')
            ->join('pigeonhole_folders as f', 'f.id', '=', 'm.folder_id')
            ->select('m.user_id', 'm.mybb_folder_id', 'm.folder_id')
            ->get();
        foreach ($existing as $row) {
            $map[(int) $row->user_id][(int) $row->mybb_folder_id] = (int) $row->folder_id;
        }

        $created = 0;
        $now = gmdate('Y-m-d H:i:s');

        foreach ($folders as $uid => $list) {
            $position = null;

            foreach ($list as $fid => $name) {
                if (isset($map[$uid][$fid])) {
                    continue;
                }

                // Linha de mapa apontando para pasta que o membro apagou: some.
                $this->db->table('mybb_migrated_pm_folders')->where('user_id', $uid)->where('mybb_folder_id', $fid)->delete();

                $owned = (int) $this->db->table('pigeonhole_folders')->where('user_id', $uid)->count();
                if ($owned >= self::FOLDERS_PER_USER) {
                    $this->bump('folders skipped (user at the 50 limit)');
                    continue;
                }

                $position ??= (int) $this->db->table('pigeonhole_folders')->where('user_id', $uid)->max('position') + 1;

                $folderId = (int) $this->db->table('pigeonhole_folders')->insertGetId([
                    'user_id'    => $uid,
                    'name'       => $this->truncate($name, self::FOLDER_NAME_MAX),
                    'color'      => null,
                    'icon'       => null,
                    'position'   => $position++,
                    'created_at' => $now,
                ]);

                $this->db->table('mybb_migrated_pm_folders')->insert([
                    'folder_id'      => $folderId,
                    'user_id'        => $uid,
                    'mybb_folder_id' => $fid,
                ]);

                $map[$uid][$fid] = $folderId;
                $created++;
            }
        }

        $this->stats['folders created'] = $created;

        return $map;
    }

    /**
     * Quem pode escrever a qualquer um (admins e grupos com a permissão
     * dialog.messageUsersWithoutPermission). Uma conversa em que um deles
     * escreveu fica aberta a respostas de todos — a mesma regra que o
     * flarum/messages aplica ao enviar e que a sua migração 2026_10_07_000003
     * aplicou às conversas existentes.
     *
     * @return array<int, true>
     */
    private function usersWhoMessageWithoutPermission(): array
    {
        if (! $this->db->getSchemaBuilder()->hasColumn('dialogs', 'anyone_can_reply')) {
            return [];
        }

        $groupIds = $this->db->table('group_permission')
            ->where('permission', 'dialog.messageUsersWithoutPermission')
            ->pluck('group_id')->map(fn ($id) => (int) $id)->all();
        $groupIds[] = 1; // Group::ADMINISTRATOR_ID

        $out = [];
        foreach ($this->db->table('group_user')->whereIn('group_id', $groupIds)->pluck('user_id') as $uid) {
            $out[(int) $uid] = true;
        }

        return $out;
    }

    /**
     * Grava uma conversa inteira numa transação: dialog, mensagens (na ordem do
     * MyBB, para que ids e `number` cresçam juntos — o não lido do
     * flarum/messages é "id > last_read_message_id"), membros com o estado de
     * leitura, metadados do grupo, menções, pastas e a linha de mapa.
     *
     * @param array{key: string, type: string, participants: list<int>, title: string, firstDateline: int, lastDateline: int, messages: list<array<string, mixed>>} $conv
     * @param list<int> $members
     * @param array<int, true> $validUids
     * @param array<int, array<int, int>> $folderMap
     * @param array<int, true> $replyAnyone
     */
    private function persistConversation(
        MybbDatabase $mybb,
        array $conv,
        array $members,
        array $validUids,
        array $folderMap,
        array $replyAnyone,
        bool $pigeonhole,
        bool $subjectHeader,
    ): int {
        $messages = $conv['messages'];

        $this->db->transaction(function () use ($mybb, $conv, $members, $validUids, $folderMap, $replyAnyone, $pigeonhole, $subjectHeader, $messages): void {
            $createdAt = $this->ts($conv['firstDateline']);
            $lastAt    = $this->ts($conv['lastDateline']);
            $isGroup   = $conv['type'] === MessagesGrouping::TYPE_GROUP;

            $dialog = [
                'type'       => $conv['type'],
                'created_at' => $createdAt,
                'updated_at' => $lastAt,
            ];
            if ($replyAnyone !== []) {
                $dialog['anyone_can_reply'] = (bool) array_filter($messages, fn ($m) => isset($replyAnyone[$m['from']]));
            }
            $dialogId = (int) $this->db->table('dialogs')->insertGetId($dialog);

            $hasIp     = $this->db->getSchemaBuilder()->hasColumn('dialog_messages', 'ip_address');
            $number    = 0;
            $prevTopic = null;
            $ids       = [];
            $mentions  = ['user' => [], 'post' => [], 'group' => [], 'tag' => []];

            foreach (array_chunk($messages, self::BATCH_SIZE) as $chunk) {
                $bodies = $this->fetchMessageBodies($mybb, array_column($chunk, 'pmid'));

                $rows = [];
                foreach ($chunk as $msg) {
                    $number++;
                    $body = $bodies[$msg['pmid']] ?? ['message' => '', 'ipaddress' => ''];
                    $raw  = $body['message'];

                    // O flarum/messages não tem assunto. Numa conversa direta
                    // (todas as PMs do par numa só) o assunto vira a primeira
                    // linha da mensagem que abre cada assunto; respostas "RE:" do
                    // mesmo assunto não repetem. Grupos já têm o assunto no nome.
                    $topic = MessagesGrouping::normalizeSubject($msg['subject']);
                    if ($subjectHeader && ! $isGroup && $topic !== '' && $topic !== $prevTopic) {
                        $raw = '[b]' . $this->escapeSubject($topic) . "[/b]\n\n" . $raw;
                    }
                    $prevTopic = $topic;

                    $xml = $this->formatter->parse(Converter::convert($raw));
                    $this->collectMentions($xml, $number, $mentions);

                    $row = [
                        'dialog_id'  => $dialogId,
                        'user_id'    => isset($validUids[$msg['from']]) ? $msg['from'] : null,
                        'content'    => $xml,
                        'number'     => $number,
                        'created_at' => $this->ts($msg['dateline']),
                        'updated_at' => $this->ts($msg['dateline']),
                    ];
                    if ($hasIp) {
                        $row['ip_address'] = $body['ipaddress'] !== '' ? $this->truncate($body['ipaddress'], 45) : null;
                    }
                    $rows[] = $row;
                }

                $this->db->table('dialog_messages')->insert($rows);
            }

            foreach ($this->db->table('dialog_messages')->where('dialog_id', $dialogId)->select('id', 'number')->cursor() as $row) {
                $ids[(int) $row->number] = (int) $row->id;
            }

            $firstId = $ids[1];
            $lastId  = $ids[$number];
            $last    = end($messages);

            $this->db->table('dialogs')->where('id', $dialogId)->update([
                'first_message_id'     => $firstId,
                'last_message_id'      => $lastId,
                'last_message_at'      => $lastAt,
                'last_message_user_id' => isset($validUids[$last['from']]) ? $last['from'] : null,
            ]);

            // Membros, leitura e pastas.
            $userRows = [];
            $filings  = [];
            foreach ($members as $uid) {
                $read   = MessagesGrouping::readState($messages, $uid);
                $filing = $pigeonhole ? MessagesGrouping::filing($messages, $uid) : ['folder' => null, 'archived' => false];

                // Arquivar no pigeonhole também marca como lido (é o que o
                // DialogFields dele faz ao arquivar), senão o selo de não lidas
                // contaria algo que o membro não vê na lista.
                if ($filing['archived']) {
                    $read['index'] = $number - 1;
                }

                $userRows[] = [
                    'dialog_id'            => $dialogId,
                    'user_id'              => $uid,
                    'joined_at'            => $createdAt,
                    'last_read_message_id' => $read['index'] >= 0 ? $ids[$read['index'] + 1] : 0,
                    'last_read_at'         => $read['readAt'] > 0 ? $this->ts($read['readAt']) : null,
                ];

                $folderId = $filing['folder'] !== null ? ($folderMap[$uid][$filing['folder']] ?? null) : null;
                if ($folderId !== null || $filing['archived']) {
                    $filings[] = [
                        'user_id'             => $uid,
                        'dialog_id'           => $dialogId,
                        'folder_id'           => $folderId,
                        'archived_message_id' => $filing['archived'] ? $lastId : null,
                    ];
                    $this->bump($filing['archived'] ? 'conversations archived (pigeonhole)' : 'conversations filed in a folder');
                }
            }
            $this->db->table('dialog_user')->insert($userRows);
            if ($filings !== []) {
                $this->db->table('pigeonhole_filings')->insert($filings);
            }

            if ($isGroup) {
                // Dono: quem abriu a conversa, se ainda existe; senão o membro
                // mais antigo — a mesma herança que o group-messages aplica
                // quando o dono sai (afterLeft).
                $starter = $messages[0]['from'];
                $this->db->table('group_dialogs')->insert([
                    'dialog_id'  => $dialogId,
                    'title'      => $this->truncate($conv['title'] !== '' ? $conv['title'] : '(no subject)', self::GROUP_TITLE_MAX),
                    'icon_url'   => null,
                    'owner_id'   => in_array($starter, $members, true) ? $starter : $members[0],
                    'created_at' => $createdAt,
                    'updated_at' => $lastAt,
                ]);
            }

            $this->persistMentions($mentions, $ids);

            $this->db->table('mybb_migrated_dialogs')->insert([
                'conversation_key' => $conv['key'],
                'dialog_id'        => $dialogId,
                'last_message_id'  => $lastId,
                'message_count'    => $number,
                'created_at'       => gmdate('Y-m-d H:i:s'),
            ]);

            $this->bump($isGroup ? 'group conversations' : 'direct conversations');
        });

        return count($messages);
    }

    /**
     * O assunto do MyBB é texto puro (o MyBB nunca o interpretou como BBCode),
     * então os caracteres que o bbcode/markdown do Flarum leriam como marcação
     * são escapados com barra — o Litedown os devolve literais.
     */
    private function escapeSubject(string $subject): string
    {
        // Inclui o @: senão "@fulano" no assunto viraria uma menção.
        return (string) preg_replace('/([\\\\`*_\[\]#<>~|!@])/', '\\\\$1', $subject);
    }

    /**
     * Guarda os ids mencionados de uma mensagem já parseada. É o que o
     * listener UpdateMentionsMetadataWhenVisible do flarum/messages faria no
     * evento Created — que um INSERT direto não dispara.
     *
     * @param array<string, array<int, list<int>>> $mentions
     */
    private function collectMentions(string $xml, int $number, array &$mentions): void
    {
        if (! str_contains($xml, 'MENTION')) {
            return;
        }

        foreach (['user' => 'USERMENTION', 'post' => 'POSTMENTION', 'group' => 'GROUPMENTION', 'tag' => 'TAGMENTION'] as $kind => $tag) {
            foreach (Utils::getAttributeValues($xml, $tag, 'id') as $id) {
                if ((int) $id > 0) {
                    $mentions[$kind][$number][] = (int) $id;
                }
            }
        }
    }

    /**
     * Grava as menções coletadas, filtrando ids que não existem (as tabelas
     * têm FK para users/posts/groups/tags).
     *
     * @param array<string, array<int, list<int>>> $mentions
     * @param array<int, int> $ids number => dialog_messages.id
     */
    private function persistMentions(array $mentions, array $ids): void
    {
        $targets = [
            'user'  => ['dialog_message_mentions_user', 'mentions_user_id', 'users'],
            'post'  => ['dialog_message_mentions_post', 'mentions_post_id', 'posts'],
            'group' => ['dialog_message_mentions_group', 'mentions_group_id', 'groups'],
            'tag'   => ['dialog_message_mentions_tag', 'mentions_tag_id', 'tags'],
        ];

        foreach ($targets as $kind => [$table, $column, $source]) {
            if ($mentions[$kind] === [] || ! $this->hasTable($table) || ! $this->hasTable($source)) {
                continue;
            }

            $wanted = array_unique(array_merge(...array_values($mentions[$kind])));
            $exists = array_flip($this->db->table($source)->whereIn('id', $wanted)->pluck('id')->map(fn ($id) => (int) $id)->all());

            $rows = [];
            foreach ($mentions[$kind] as $number => $list) {
                foreach (array_unique($list) as $id) {
                    if (isset($exists[$id])) {
                        $rows[] = ['dialog_message_id' => $ids[$number], $column => $id];
                    }
                }
            }

            if ($rows !== []) {
                $this->db->table($table)->insertOrIgnore($rows);
            }
        }
    }

    /**
     * Busca os corpos das PMs (campo pesado, adiado durante o agrupamento) por
     * pmid, em lote. Devolve um mapa pmid => ['message' => texto já corrigido,
     * 'ipaddress' => ip legível].
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

    private function hasTable(string $table): bool
    {
        return $this->hasTableCache[$table] ??= $this->db->getSchemaBuilder()->hasTable($table);
    }

    private function bump(string $stat): void
    {
        $this->stats[$stat] = ($this->stats[$stat] ?? 0) + 1;
    }

    private function printStats(): void
    {
        ksort($this->stats);
        foreach ($this->stats as $label => $value) {
            $this->info("  $label: $value");
        }
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
