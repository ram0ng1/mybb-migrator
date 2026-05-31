<?php

namespace Ramon\MybbMigrator\Support;

/**
 * Verificação de senha fiel ao plugin DVZ Hash do MyBB. Cada algoritmo define
 * qual entrada foi passada ao password_hash() do PHP:
 *
 *  - argon2id / argon2i : o texto puro.
 *  - sha512_bcrypt      : hash('sha512', texto) embrulhado em bcrypt.
 *  - mybb_*             : o hash legado md5(md5(salt).md5(texto)) embrulhado.
 *  - mybb / ''          : o próprio hash legado md5(md5(salt).md5(texto)).
 *
 * Lógica pura, sem dependência do Flarum, para ser testável isoladamente.
 */
final class MybbPassword
{
    public static function legacyHash(string $salt, string $plaintext): string
    {
        return md5(md5($salt) . md5($plaintext));
    }

    public static function verify(string $algorithm, string $hash, string $salt, string $plaintext): bool
    {
        if ($hash === '' || $plaintext === '') {
            return false;
        }

        return match ($algorithm) {
            'argon2id', 'argon2i'                  => password_verify($plaintext, $hash),
            'sha512_bcrypt'                        => password_verify(hash('sha512', $plaintext), $hash),
            'mybb_argon2id', 'mybb_argon2i', 'mybb_bcrypt'
                                                   => password_verify(self::legacyHash($salt, $plaintext), $hash),
            'mybb', ''                             => hash_equals($hash, self::legacyHash($salt, $plaintext)),
            default                                => false,
        };
    }
}
