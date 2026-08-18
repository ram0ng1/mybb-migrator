<?php

namespace Ramon\MybbMigrator\Gui;

use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Ramon\MybbMigrator\MybbDatabase;
use Ramon\MybbMigrator\Support\ImageStore;

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
        protected Paths $paths,
    ) {
    }

    /**
     * Configuração e estado da migração de mídia (aba "Imagens"). Só leitura de
     * settings — barato o bastante para acompanhar o polling.
     *
     * @return array<string, mixed>
     */
    public function media(): array
    {
        return [
            'image_hosts'       => (string) ($this->settings->get('mybb-migrator.image_hosts') ?? ''),
            'image_limit'       => (int) ($this->settings->get('mybb-migrator.image_limit') ?? 50),
            'image_max_mb'      => (int) ($this->settings->get('mybb-migrator.image_max_mb') ?? 200),
            'image_max_file_mb' => (int) ($this->settings->get('mybb-migrator.image_max_file_mb') ?? 10),
            'attachments_dir'   => (string) ($this->settings->get('mybb-migrator.attachments_dir') ?? ''),
            'fof_upload'        => $this->extensions->isEnabled('fof-upload'),
            'upload_table'      => $this->hasTable('fof_upload_files'),
            'map_table'         => $this->hasTable('mybb_migrated_images'),
            'directory'         => rtrim($this->paths->public, '/\\')
                . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . ImageStore::DIR,
        ];
    }

    /**
     * Agregados do mapa `mybb_migrated_images`: quanto já foi internalizado,
     * quanto falhou e quanto de disco isso custou.
     *
     * @return array<string, int>
     */
    public function mediaStats(): array
    {
        $empty = ['images_ok' => 0, 'images_failed' => 0, 'attachments_ok' => 0, 'attachments_failed' => 0, 'bytes' => 0];

        if (! $this->hasTable('mybb_migrated_images')) {
            return $empty;
        }

        try {
            $rows = $this->db->table('mybb_migrated_images')
                ->selectRaw('kind, status, COUNT(*) as n, COALESCE(SUM(size), 0) as bytes')
                ->groupBy('kind', 'status')
                ->get();
        } catch (\Throwable $e) {
            return $empty;
        }

        $out = $empty;
        foreach ($rows as $row) {
            $key = ((string) $row->kind === 'attachment' ? 'attachments' : 'images')
                . ((string) $row->status === 'ok' ? '_ok' : '_failed');
            $out[$key] = ($out[$key] ?? 0) + (int) $row->n;
            $out['bytes'] += (int) $row->bytes;
        }

        return $out;
    }

    /**
     * Contagens origem + destino, COM CACHE.
     *
     * `source()` faz COUNT(*) em tabelas InnoDB grandes: no fórum de referência
     * são ~12 s só nele. Pagar isso a cada abertura do painel deixava a página
     * de configurações parada esperando. O resultado passa a viver num setting
     * com TTL — a primeira visita computa, as seguintes leem instantâneo, e o
     * painel manda `refresh=1` quando um passo termina e os números realmente
     * mudaram.
     *
     * @return array<string, mixed>
     */
    public function counts(bool $force = false): array
    {
        $ttl = max(30, (int) ($this->settings->get('mybb-migrator.counts_ttl') ?: 300));
        $cached = json_decode((string) ($this->settings->get('mybb-migrator.counts_cache') ?? ''), true);

        if (! $force && is_array($cached) && isset($cached['computed_at'])) {
            $age = time() - (int) $cached['computed_at'];
            if ($age >= 0 && $age < $ttl) {
                return $cached + ['cached' => true, 'age' => $age];
            }
        }

        $data = [
            'source'      => $this->source(),
            'target'      => $this->target(),
            'mediaStats'  => $this->mediaStats(),
            'computed_at' => time(),
        ];

        $this->settings->set(
            'mybb-migrator.counts_cache',
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $data + ['cached' => false, 'age' => 0];
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

    private function hasTable(string $table): bool
    {
        try {
            return $this->db->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
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
