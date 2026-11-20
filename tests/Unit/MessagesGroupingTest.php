<?php

namespace Ramon\MybbMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\MybbMigrator\Console\MessagesGrouping;

class MessagesGroupingTest extends TestCase
{
    /**
     * Múltiplos prefixos Re:/RE:/Fwd:/FWD:, em qualquer ordem e com espaços
     * extras, são removidos e o assunto original aparece intacto.
     */
    public function test_normalize_strips_re_prefixes(): void
    {
        $this->assertSame('Hello', MessagesGrouping::normalizeSubject('Re: Re: Hello'));
        $this->assertSame('Hello', MessagesGrouping::normalizeSubject('RE: RE: Hello'));
        $this->assertSame('Hello', MessagesGrouping::normalizeSubject('Fwd: Hello'));
        $this->assertSame('hello', MessagesGrouping::normalizeSubject('re:  re: hello'));
        $this->assertSame('Hello', MessagesGrouping::normalizeSubject('Re: Fwd: Re: Hello'));
        $this->assertSame('Hello', MessagesGrouping::normalizeSubject('   RE:   Hello   '));
    }

    /**
     * Assuntos sem prefixo conhecido não são alterados (exceto trim das pontas).
     */
    public function test_normalize_preserves_unprefixed(): void
    {
        $this->assertSame('Hello', MessagesGrouping::normalizeSubject('Hello'));
        $this->assertSame('Reaction: bug', MessagesGrouping::normalizeSubject('Reaction: bug'));
        $this->assertSame('Forward me', MessagesGrouping::normalizeSubject('Forward me'));
        $this->assertSame('Pão de queijo', MessagesGrouping::normalizeSubject('Pão de queijo'));
    }

    /**
     * A chave é a mesma independentemente da ordem dos uids — conversas são
     * conjuntos de participantes, não tuplas ordenadas.
     */
    public function test_group_key_is_deterministic_regardless_of_uid_order(): void
    {
        $a = MessagesGrouping::groupKey([3, 1, 2], 'X');
        $b = MessagesGrouping::groupKey([1, 2, 3], 'X');
        $c = MessagesGrouping::groupKey([2, 3, 1], 'X');

        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
    }

    /**
     * Mudar o assunto normalizado muda a chave (assunto faz parte do grupo).
     */
    public function test_group_key_changes_when_subject_changes(): void
    {
        $a = MessagesGrouping::groupKey([1, 2], 'Hello');
        $b = MessagesGrouping::groupKey([1, 2], 'World');

        $this->assertNotSame($a, $b);
    }

    /**
     * Mudar o conjunto de participantes muda a chave — duas conversas com o
     * mesmo título mas pessoas diferentes não devem colidir.
     */
    public function test_group_key_changes_when_participant_set_changes(): void
    {
        $a = MessagesGrouping::groupKey([1, 2], 'Hello');
        $b = MessagesGrouping::groupKey([1, 2, 3], 'Hello');
        $c = MessagesGrouping::groupKey([1, 3], 'Hello');

        $this->assertNotSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertNotSame($b, $c);
    }

    /**
     * Duas pessoas viram SEMPRE a mesma conversa direta, qualquer que seja o
     * assunto e a ordem — o flarum/messages só tem uma conversa direta por par.
     */
    public function test_two_people_share_one_direct_conversation_whatever_the_subject(): void
    {
        $a = MessagesGrouping::conversationsOf(2, [3], [], 'Feather blades?');
        $b = MessagesGrouping::conversationsOf(3, [2], [], 'Meetup');

        $this->assertCount(1, $a);
        $this->assertSame('direct', $a[0]['type']);
        $this->assertSame('direct:2,3', $a[0]['key']);
        $this->assertSame($a[0]['key'], $b[0]['key']);
        $this->assertSame([2, 3], $a[0]['participants']);
    }

    /**
     * Três ou mais participantes viram grupo, e o assunto separa grupos.
     */
    public function test_three_or_more_people_form_a_group_keyed_by_subject(): void
    {
        $a = MessagesGrouping::conversationsOf(4, [2, 3, 5], [], 'Group buy');
        $b = MessagesGrouping::conversationsOf(2, [5, 4, 3], [], 'Group buy');
        $c = MessagesGrouping::conversationsOf(2, [5, 4, 3], [], 'Other');

        $this->assertSame('group', $a[0]['type']);
        $this->assertSame([2, 3, 4, 5], $a[0]['participants']);
        $this->assertSame($a[0]['key'], $b[0]['key']);
        $this->assertNotSame($a[0]['key'], $c[0]['key']);
    }

    /**
     * Quem está em BCC não entra na conversa dos visíveis: ganha uma conversa
     * direta própria com o remetente.
     */
    public function test_bcc_recipients_get_their_own_direct_conversation(): void
    {
        $out = MessagesGrouping::conversationsOf(5, [2], [7], 'Secret');

        $this->assertCount(2, $out);
        $this->assertSame('direct:2,5', $out[0]['key']);
        $this->assertSame('direct:5,7', $out[1]['key']);
    }

    /**
     * Mensagem para si mesmo não vira conversa; o remetente repetido em "to"
     * é ignorado.
     */
    public function test_message_to_self_has_no_conversation(): void
    {
        $this->assertSame([], MessagesGrouping::conversationsOf(2, [2], [], 'Note'));
        $this->assertSame('direct:2,3', MessagesGrouping::conversationsOf(2, [2, 3], [], 'x')[0]['key']);
    }

    public function test_parse_recipients_reads_to_and_bcc_and_rejects_garbage(): void
    {
        $raw = serialize(['to' => ['3', '2'], 'bcc' => ['7']]);

        $this->assertSame(['to' => [2, 3], 'bcc' => [7]], MessagesGrouping::parseRecipients($raw));
        $this->assertNull(MessagesGrouping::parseRecipients(''));
        $this->assertNull(MessagesGrouping::parseRecipients('not serialized'));
        $this->assertNull(MessagesGrouping::parseRecipients(serialize(['to' => []])));
    }

    /**
     * Cópias no formato do GROUP_CONCAT; em duplicidade vale a que não está na
     * lixeira.
     */
    public function test_parse_copies(): void
    {
        $copies = MessagesGrouping::parseCopies('3:1:0:0:3,2:2:1:0:3,3:4:1:10:3');

        $this->assertSame(['folder' => 1, 'status' => 0, 'readtime' => 0, 'toid' => 3], $copies[3]);
        $this->assertSame(2, $copies[2]['folder']);

        $copies = MessagesGrouping::parseCopies('3:4:0:0:3,3:5:1:9:3');
        $this->assertSame(5, $copies[3]['folder']);
    }

    /**
     * Só as pastas personalizadas (5+) saem, com o nome decodificado do
     * htmlspecialchars do MyBB e na ordem original.
     */
    public function test_parse_pm_folders_keeps_custom_ones_only(): void
    {
        $raw = '0**$%%$1**$%%$2**$%%$3**$%%$4**$%%$6**Fam&iacute;lia &amp; amigos 🏠$%%$5**Trades$%%$7**';

        $this->assertSame(
            [6 => 'Família & amigos 🏠', 5 => 'Trades', 7 => 'Untitled folder'],
            MessagesGrouping::parsePmFolders($raw)
        );
        $this->assertSame([], MessagesGrouping::parsePmFolders('1**$%%$2**$%%$3**$%%$4**'));
        $this->assertSame([], MessagesGrouping::parsePmFolders(''));
    }

    /**
     * O ponteiro de leitura para antes da primeira não lida; o que o próprio
     * usuário escreveu, o que está na lixeira e o que ele apagou contam como
     * lidos.
     */
    public function test_read_state_stops_before_first_unread(): void
    {
        $messages = [
            ['from' => 2, 'dateline' => 100, 'copies' => [3 => ['folder' => 1, 'status' => 1, 'readtime' => 150]]],
            ['from' => 3, 'dateline' => 200, 'copies' => []],
            ['from' => 2, 'dateline' => 300, 'copies' => [3 => ['folder' => 1, 'status' => 0, 'readtime' => 0]]],
            ['from' => 2, 'dateline' => 400, 'copies' => [3 => ['folder' => 1, 'status' => 1, 'readtime' => 450]]],
        ];

        $this->assertSame(['index' => 1, 'readAt' => 200], MessagesGrouping::readState($messages, 3));
        $this->assertSame(['index' => 3, 'readAt' => 400], MessagesGrouping::readState($messages, 2));

        $trash = [['from' => 7, 'dateline' => 10, 'copies' => [3 => ['folder' => 4, 'status' => 0, 'readtime' => 0]]]];
        $this->assertSame(0, MessagesGrouping::readState($trash, 3)['index']);

        $unread = [['from' => 7, 'dateline' => 10, 'copies' => [3 => ['folder' => 1, 'status' => 0, 'readtime' => 0]]]];
        $this->assertSame(['index' => -1, 'readAt' => 0], MessagesGrouping::readState($unread, 3));
    }

    /**
     * Pasta = a da cópia mais recente numa pasta personalizada; arquivada =
     * nenhuma cópia fora da lixeira.
     */
    public function test_filing_picks_latest_custom_folder_and_archives_trash_only(): void
    {
        $messages = [
            ['copies' => [2 => ['folder' => 5]]],
            ['copies' => [2 => ['folder' => 6]]],
            ['copies' => [2 => ['folder' => 1]]],
        ];
        $this->assertSame(['folder' => 6, 'archived' => false], MessagesGrouping::filing($messages, 2));

        $this->assertSame(['folder' => null, 'archived' => true], MessagesGrouping::filing([['copies' => [3 => ['folder' => 4]]]], 3));
        $this->assertSame(['folder' => null, 'archived' => true], MessagesGrouping::filing([['copies' => []]], 3));
        $this->assertSame(['folder' => null, 'archived' => false], MessagesGrouping::filing([['copies' => [3 => ['folder' => 2]]]], 3));
    }
}
