<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\Support\MybbPassword;
use Symfony\Component\Console\Input\InputOption;

/**
 * Sobrescreve a senha de 6 usuários (ramon + 1 por algoritmo) na tabela
 * companheira `mybb_legacy_passwords` com um hash gerado por nós (formato
 * idêntico ao DVZ Hash do MyBB), e devolve os pares (usuário, senha em texto
 * puro) para você logar e validar o ciclo completo.
 *
 * Tudo o que sai é uma SENHA DE TESTE — os outros usuários permanecem com a
 * senha original do MyBB intacta.
 */
class TestCredentialsCommand extends AbstractCommand
{
    use MybbConnectionOptions;

    private const ALGORITHMS = ['argon2id', 'argon2i', 'sha512_bcrypt', 'mybb_argon2id', 'mybb_argon2i'];

    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:test-credentials')
            ->setDescription('Gera senhas de teste para ramon + 5 usuários (1 por algoritmo) e devolve os pares (usuário, senha).')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Username específico a incluir além dos 5 algoritmos.', 'ramon')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirma execução.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('force')) {
            $this->error('Rode com --force.');
            return 1;
        }

        $selected = [];
        $forcedUsername = (string) $this->input->getOption('username');

        $rows = $this->db->table('mybb_legacy_passwords as lp')
            ->join('users as u', 'u.id', '=', 'lp.user_id')
            ->whereRaw('LOWER(u.username) = ?', [strtolower($forcedUsername)])
            ->select('lp.user_id', 'u.username', 'lp.algorithm', 'lp.salt')
            ->limit(1)
            ->get();

        foreach ($rows as $row) {
            $selected[$row->user_id] = (array) $row;
        }

        foreach (self::ALGORITHMS as $algorithm) {
            $row = $this->db->table('mybb_legacy_passwords as lp')
                ->join('users as u', 'u.id', '=', 'lp.user_id')
                ->where('lp.algorithm', $algorithm)
                ->whereNotIn('lp.user_id', array_keys($selected))
                ->select('lp.user_id', 'u.username', 'lp.algorithm', 'lp.salt')
                ->orderBy('lp.user_id')
                ->limit(1)
                ->first();

            if ($row === null) {
                $this->info("  (sem usuário disponível para algoritmo '{$algorithm}' — pulado)");
                continue;
            }

            $selected[$row->user_id] = (array) $row;
        }

        if ($selected === []) {
            $this->error('Nenhum usuário elegível encontrado em mybb_legacy_passwords (rodou mybb:users?).');
            return 1;
        }

        $this->info('');
        $this->info('==============================================================');
        $this->info(' CREDENCIAIS DE TESTE — SOBRESCREVEM A SENHA APENAS NESTES USUÁRIOS');
        $this->info('==============================================================');
        $this->info(sprintf(' %-20s %-16s %s', 'USERNAME', 'ALGORITMO', 'SENHA DE TESTE'));
        $this->info(' ------------------------------------------------------------');

        foreach ($selected as $userId => $info) {
            $algorithm = (string) $info['algorithm'];
            $salt = (string) $info['salt'];
            $plaintext = self::generatePassword();
            $newHash = self::computeHash($algorithm, $salt, $plaintext);

            $this->db->table('mybb_legacy_passwords')
                ->where('user_id', $userId)
                ->update(['hash' => $newHash]);

            $this->db->table('users')
                ->where('id', $userId)
                ->update(['password' => '']);

            $this->info(sprintf(' %-20s %-16s %s', (string) $info['username'], $algorithm, $plaintext));
        }

        $this->info(' ------------------------------------------------------------');
        $this->info(' Use essas credenciais para validar o login no Flarum.');
        $this->info(' No primeiro login bem-sucedido o verificador re-hasheia para');
        $this->info(' bcrypt do Flarum e apaga a linha companheira.');
        $this->info('==============================================================');

        return 0;
    }

    private static function generatePassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    private static function computeHash(string $algorithm, string $salt, string $plaintext): string
    {
        $argonOptions = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1];

        return match ($algorithm) {
            'argon2id'      => password_hash($plaintext, PASSWORD_ARGON2ID, $argonOptions),
            'argon2i'       => password_hash($plaintext, PASSWORD_ARGON2I, $argonOptions),
            'sha512_bcrypt' => password_hash(hash('sha512', $plaintext), PASSWORD_BCRYPT, ['cost' => 12]),
            'mybb_argon2id' => password_hash(MybbPassword::legacyHash($salt, $plaintext), PASSWORD_ARGON2ID, $argonOptions),
            'mybb_argon2i'  => password_hash(MybbPassword::legacyHash($salt, $plaintext), PASSWORD_ARGON2I, $argonOptions),
            'mybb_bcrypt'   => password_hash(MybbPassword::legacyHash($salt, $plaintext), PASSWORD_BCRYPT, ['cost' => 12]),
            default         => MybbPassword::legacyHash($salt, $plaintext),
        };
    }
}
