<?php

namespace Ramon\MybbMigrator\Gui;

use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\MybbDatabase;

/**
 * Contagens de origem (MyBB) e destino (Flarum) + pré-checagem de extensões,
 * para o painel comparar o progresso. Centralizado para reuso entre os
 * controllers de status e de teste de conexão.
 */
class MigrationSnapshot
{
    /** Extensões-alvo cujas tabelas a migração escreve (README §1). */
    private const TARGET_EXTENSIONS = [
        'flarum-tags'                 => true,  // required
        'flarum-likes'                => false,
        'flarum-mentions'             => false,
        'flarum-subscriptions'        => false,
        'flarum-suspend'              => false,
        'flarum-sticky'               => false,
        'flarum-lock'                 => false,
        'flarum-approval'             => false,
        'flarum-bbcode'               => false,
        'flarum-markdown'             => false,
        'fof-byobu'                   => false,
        'fof-polls'                   => false,
        'fof-upload'                  => false,
        'fof-signature'               => false, // assinaturas dos posts
        'huseyinfiliz-traderfeedback' => false,
    ];

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected ExtensionManager $extensions,
    ) {
    }

    /**
     * Contagens do banco MyBB de origem.
     *
     * @return array{ok: bool, error: ?string, counts: array<string, int>}
     */
    public function source(): array
    {
        try {
            $mybb = MybbDatabase::fromSettings($this->settings);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'counts' => []];
        }

        $tables = [
            'users'    => 'users',
            'threads'  => 'threads',
            'posts'    => 'posts',
            'forums'   => 'forums',
            'messages' => 'privatemessages',
            'polls'    => 'polls',
        ];

        $counts = [];
        $error = null;
        foreach ($tables as $label => $table) {
            try {
                $counts[$label] = (int) $mybb->scalar('SELECT COUNT(*) FROM ' . $mybb->table($table));
            } catch (\Throwable $e) {
                // tabela ausente nessa instalação MyBB — ignora a contagem
                $error ??= $e->getMessage();
            }
        }

        // Se nem users contou, a conexão provavelmente falhou.
        $ok = array_key_exists('users', $counts);

        return ['ok' => $ok, 'error' => $ok ? null : $error, 'counts' => $counts];
    }

    /**
     * Contagens do Flarum de destino.
     *
     * @return array<string, int>
     */
    public function target(): array
    {
        $counts = [
            'users'       => $this->safeCount('users'),
            'discussions' => $this->safeCount('discussions'),
            'posts'       => $this->safeCount('posts'),
            'tags'        => $this->safeCount('tags'),
        ];

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function preflight(): array
    {
        $schema = $this->db->getSchemaBuilder();

        $exts = [];
        foreach (self::TARGET_EXTENSIONS as $id => $required) {
            $exts[] = [
                'id'       => $id,
                'enabled'  => $this->extensions->isEnabled($id),
                'required' => $required,
            ];
        }

        return [
            'legacy_table' => $schema->hasTable('mybb_legacy_passwords'),
            'steps_table'  => $schema->hasTable('mybb_migration_steps'),
            'extensions'   => $exts,
        ];
    }

    private function safeCount(string $table): int
    {
        try {
            return (int) $this->db->table($table)->count();
        } catch (\Throwable $e) {
            return -1; // tabela inexistente (ex.: tags desabilitado)
        }
    }
}
