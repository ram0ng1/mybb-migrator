<?php

namespace Ramon\MybbMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\MybbMigrator\Support\Charset;

class CharsetTest extends TestCase
{
    /**
     * Repara mojibake usando os bytes REAIS extraídos do banco MyBB.
     */
    public function test_repairs_double_encoded_utf8_from_real_bytes(): void
    {
        $this->assertSame('á', Charset::fix(hex2bin('C383C2A1')));
        $this->assertSame('Christian Lévesque', Charset::fix(hex2bin('43687269737469616E204CC383C2A9766573717565')));
        $this->assertSame('Feldgrün', Charset::fix(hex2bin('46656C646772C383C2BC6E')));
    }

    /**
     * Texto já correto (ASCII, UTF-8 simples, emoji) não deve ser alterado.
     */
    public function test_leaves_clean_text_untouched(): void
    {
        $this->assertSame('Hello world', Charset::fix('Hello world'));
        $this->assertSame('café', Charset::fix('café'));
        $this->assertSame('ação', Charset::fix('ação'));
        $this->assertSame('🎉 festa', Charset::fix('🎉 festa'));
        $this->assertSame('', Charset::fix(''));
        $this->assertSame('', Charset::fix(null));
    }
}
