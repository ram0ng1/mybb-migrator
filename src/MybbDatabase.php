<?php

namespace Ramon\MybbMigrator;

use Flarum\Settings\SettingsRepositoryInterface;
use PDO;

/**
 * Conexão de leitura ao banco do MyBB. Compartilha as mesmas chaves de
 * configuração da extensão michaelbelgium/mybb-to-flarum (mybb_host, mybb_user,
 * mybb_password, mybb_db, mybb_prefix) para que a configuração seja única.
 */
class MybbDatabase
{
    private PDO $pdo;

    public function __construct(
        string $host,
        string $user,
        string $password,
        string $db,
        private string $prefix,
        int $port = 3306,
    ) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db);

        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public static function fromSettings(SettingsRepositoryInterface $settings): self
    {
        return new self(
            (string) ($settings->get('mybb_host') ?: '127.0.0.1'),
            (string) ($settings->get('mybb_user') ?: 'root'),
            (string) ($settings->get('mybb_password') ?? ''),
            (string) ($settings->get('mybb_db') ?: 'mybb'),
            (string) ($settings->get('mybb_prefix') ?: 'mybb_'),
            (int) ($settings->get('mybb_port') ?: 3306),
        );
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function table(string $name): string
    {
        return $this->prefix . $name;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Executa um SELECT bufferizado (para conjuntos pequenos/médios).
     *
     * @param array<int|string, mixed> $bindings
     */
    public function select(string $sql, array $bindings = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return $stmt;
    }

    public function scalar(string $sql, array $bindings = []): mixed
    {
        return $this->select($sql, $bindings)->fetchColumn();
    }

    /**
     * Itera um SELECT grande sem carregar tudo na memória (cursor unbuffered).
     *
     * @return \Generator<array<string, mixed>>
     */
    public function cursor(string $sql, array $bindings = []): \Generator
    {
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);

            while (($row = $stmt->fetch()) !== false) {
                yield $row;
            }
        } finally {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }
}
