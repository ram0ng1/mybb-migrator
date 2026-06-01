<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Settings\SettingsRepositoryInterface;
use Ramon\MybbMigrator\MybbDatabase;
use Symfony\Component\Console\Input\InputOption;

/**
 * Compartilha as opções de conexão (host, user, password, db, prefix) entre
 * todos os comandos de migração. As mesmas chaves de configuração da
 * michaelbelgium/mybb-to-flarum são usadas, e os valores ficam persistidos no
 * settings após o primeiro comando — chamadas seguintes não precisam repeti-las.
 */
trait MybbConnectionOptions
{
    protected function addMybbConnectionOptions(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'MyBB database host', null)
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'MyBB database port', null)
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'MyBB database user', null)
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'MyBB database password', null)
            ->addOption('db', 'd', InputOption::VALUE_REQUIRED, 'MyBB database name', null)
            ->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'MyBB table prefix', null);
    }

    protected function buildMybbDatabase(SettingsRepositoryInterface $settings): MybbDatabase
    {
        $values = [
            'mybb_host'     => $this->input->getOption('host')     ?: ($settings->get('mybb_host')     ?: '127.0.0.1'),
            'mybb_port'     => (int) ($this->input->getOption('port')     ?: ($settings->get('mybb_port')     ?: 3306)),
            'mybb_user'     => $this->input->getOption('user')     ?: ($settings->get('mybb_user')     ?: 'root'),
            'mybb_password' => $this->input->getOption('password') ?? ($settings->get('mybb_password') ?: ''),
            'mybb_db'       => $this->input->getOption('db')       ?: ($settings->get('mybb_db')       ?: 'mybb'),
            'mybb_prefix'   => $this->input->getOption('prefix')   ?: ($settings->get('mybb_prefix')   ?: 'mybb_'),
        ];

        foreach ($values as $key => $value) {
            if ($settings->get($key) !== (string) $value) {
                $settings->set($key, (string) $value);
            }
        }

        return new MybbDatabase(
            (string) $values['mybb_host'],
            (string) $values['mybb_user'],
            (string) $values['mybb_password'],
            (string) $values['mybb_db'],
            (string) $values['mybb_prefix'],
            (int) $values['mybb_port'],
        );
    }
}
