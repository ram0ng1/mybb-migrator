<?php

namespace Ramon\MybbMigrator;

use Flarum\Database\AbstractModel;

/**
 * Tabela companheira que guarda o hash original do MyBB por usuário, usada
 * pelo verificador de senha legado até o primeiro login bem-sucedido (quando
 * a senha é re-hasheada para bcrypt do Flarum e a linha é removida).
 *
 * @property int $user_id
 * @property string $algorithm
 * @property string $hash
 * @property string $salt
 */
class LegacyPassword extends AbstractModel
{
    protected $table = 'mybb_legacy_passwords';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
}
