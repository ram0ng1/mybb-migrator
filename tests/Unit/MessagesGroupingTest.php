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
}
