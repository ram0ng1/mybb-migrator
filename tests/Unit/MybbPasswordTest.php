<?php

namespace Ramon\MybbMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\MybbMigrator\Support\MybbPassword;

class MybbPasswordTest extends TestCase
{
    private string $pw = 'Senha-Teste_123!';
    private string $wrong = 'errada';
    private string $salt = 'EYOy8Big';

    public function test_argon2_direct(): void
    {
        $opts = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1];

        $h = password_hash($this->pw, PASSWORD_ARGON2ID, $opts);
        $this->assertTrue(MybbPassword::verify('argon2id', $h, $this->salt, $this->pw));
        $this->assertFalse(MybbPassword::verify('argon2id', $h, $this->salt, $this->wrong));

        $h = password_hash($this->pw, PASSWORD_ARGON2I, $opts);
        $this->assertTrue(MybbPassword::verify('argon2i', $h, $this->salt, $this->pw));
    }

    public function test_sha512_bcrypt(): void
    {
        $h = password_hash(hash('sha512', $this->pw), PASSWORD_BCRYPT, ['cost' => 12]);
        $this->assertTrue(MybbPassword::verify('sha512_bcrypt', $h, $this->salt, $this->pw));
        $this->assertFalse(MybbPassword::verify('sha512_bcrypt', $h, $this->salt, $this->wrong));
    }

    public function test_mybb_wrapped_legacy(): void
    {
        $opts = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1];
        $legacy = MybbPassword::legacyHash($this->salt, $this->pw);

        $h = password_hash($legacy, PASSWORD_ARGON2ID, $opts);
        $this->assertTrue(MybbPassword::verify('mybb_argon2id', $h, $this->salt, $this->pw));
        $this->assertFalse(MybbPassword::verify('mybb_argon2id', $h, $this->salt, $this->wrong));

        $h = password_hash($legacy, PASSWORD_ARGON2I, $opts);
        $this->assertTrue(MybbPassword::verify('mybb_argon2i', $h, $this->salt, $this->pw));
    }

    public function test_pure_legacy_md5(): void
    {
        $h = MybbPassword::legacyHash($this->salt, $this->pw);
        $this->assertTrue(MybbPassword::verify('mybb', $h, $this->salt, $this->pw));
        $this->assertTrue(MybbPassword::verify('', $h, $this->salt, $this->pw));
        $this->assertFalse(MybbPassword::verify('mybb', $h, $this->salt, $this->wrong));
    }

    public function test_empty_inputs_and_unknown_algorithm(): void
    {
        $this->assertFalse(MybbPassword::verify('argon2id', '', $this->salt, $this->pw));
        $this->assertFalse(MybbPassword::verify('argon2id', 'x', $this->salt, ''));
        $this->assertFalse(MybbPassword::verify('weird-algo', 'x', $this->salt, $this->pw));
    }
}
