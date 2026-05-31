<?php

namespace Ramon\MybbMigrator\Auth;

use Flarum\User\User;
use Ramon\MybbMigrator\LegacyPassword;
use Ramon\MybbMigrator\Support\MybbPassword;

/**
 * Checker de senha registrado via Extend\Auth->addPasswordChecker. Permite que
 * usuários migrados entrem com a MESMA senha do MyBB. No primeiro login válido
 * a senha é re-hasheada para o bcrypt do Flarum e a linha legada é apagada, de
 * modo que logins seguintes usam o caminho nativo do core.
 *
 * Retorna true (senha confere), ou null (não se aplica / não confere) para que
 * outros checkers ainda possam decidir — nunca false, que abortaria a cadeia.
 */
class MybbPasswordChecker
{
    public function __invoke(User $user, #[\SensitiveParameter] string $password): ?bool
    {
        $legacy = LegacyPassword::find($user->id);

        if ($legacy === null) {
            return null;
        }

        $valid = MybbPassword::verify($legacy->algorithm, $legacy->hash, $legacy->salt, $password);

        if (! $valid) {
            return null;
        }

        $user->changePassword($password);
        $user->save();
        $legacy->delete();

        return true;
    }
}
