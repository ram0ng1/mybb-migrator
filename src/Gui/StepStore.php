<?php

namespace Ramon\MybbMigrator\Gui;

use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * Acesso à tabela `mybb_migration_steps` e aos arquivos de log por passo.
 * Compartilhado entre o {@see \Ramon\MybbMigrator\Console\GuiRunCommand} (escreve)
 * e os controllers de API (leem).
 */
class StepStore
{
    /**
     * Segundos (default) sem atualização no log até considerar um "running"
     * travado. Sobrescrevível por `mybb-migrator.stale_seconds`. Alto de
     * propósito: um passo vivo em operação silenciosa longa NÃO pode virar falha
     * (mentira na UI) nem reabrir o guard de concorrência (risco de spawn duplo).
     */
    public const STALE_SECONDS = 600;

    /** Piso de segurança: nunca considerar travado abaixo disto. */
    private const STALE_MIN = 120;

    private const TABLE = 'mybb_migration_steps';

    public function __construct(
        protected ConnectionInterface $db,
        protected Paths $paths,
        protected SettingsRepositoryInterface $settings,
    ) {
    }

    /** Limiar de "travado" efetivo (setting, com piso de segurança). */
    public function staleSeconds(): int
    {
        $v = (int) ($this->settings->get('mybb-migrator.stale_seconds') ?? self::STALE_SECONDS);

        return max(self::STALE_MIN, $v);
    }

    public function logDir(): string
    {
        return rtrim($this->paths->storage, '/\\')
            . DIRECTORY_SEPARATOR . 'logs'
            . DIRECTORY_SEPARATOR . 'mybb-migrator';
    }

    public function logPath(string $step): string
    {
        return $this->logDir() . DIRECTORY_SEPARATOR . $this->safe($step) . '.log';
    }

    public function ensureLogDir(): void
    {
        $dir = $this->logDir();
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    public function markRunning(string $step, int $pid, string $logPath): void
    {
        $now = $this->now();
        $this->db->table(self::TABLE)->updateOrInsert(
            ['step' => $step],
            [
                'status'      => 'running',
                'pid'         => $pid,
                'exit_code'   => null,
                'summary'     => null,
                'log_path'    => $logPath,
                'started_at'  => $now,
                'finished_at' => null,
                'updated_at'  => $now,
            ]
        );
    }

    public function heartbeat(string $step): void
    {
        $this->db->table(self::TABLE)->where('step', $step)->update(['updated_at' => $this->now()]);
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function markFinished(string $step, int $exitCode, array $summary): void
    {
        $now = $this->now();
        $this->db->table(self::TABLE)->where('step', $step)->update([
            'status'      => $exitCode === 0 ? 'done' : 'failed',
            'exit_code'   => $exitCode,
            'summary'     => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'finished_at' => $now,
            'updated_at'  => $now,
        ]);
    }

    public function markSkipped(string $step): void
    {
        $now = $this->now();
        $this->db->table(self::TABLE)->updateOrInsert(
            ['step' => $step],
            ['status' => 'skipped', 'updated_at' => $now]
        );
    }

    /** Marca como falho um passo que ficou "running" sem heartbeat (processo morto). */
    public function clearStale(): void
    {
        $rows = $this->db->table(self::TABLE)->where('status', 'running')->get();
        foreach ($rows as $row) {
            if ($this->isStaleRow((array) $row)) {
                $this->db->table(self::TABLE)->where('step', $row->step)->update([
                    'status'      => 'failed',
                    'finished_at' => $this->now(),
                    'updated_at'  => $this->now(),
                ]);
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>> indexado por step
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->db->table(self::TABLE)->get() as $row) {
            $out[$row->step] = (array) $row;
        }

        return $out;
    }

    public function runningStep(): ?string
    {
        $rows = $this->db->table(self::TABLE)->where('status', 'running')->get();
        foreach ($rows as $row) {
            if (! $this->isStaleRow((array) $row)) {
                return $row->step;
            }
        }

        return null;
    }

    public function isAnyRunning(): bool
    {
        return $this->runningStep() !== null;
    }

    public function tail(string $step, int $lines = 200): string
    {
        $path = $this->logPath($step);
        if (! is_file($path)) {
            return '';
        }

        $content = (string) @file_get_contents($path);
        if ($content === '') {
            return '';
        }

        $all = preg_split("/\r\n|\n|\r/", rtrim($content, "\r\n"));
        $slice = array_slice($all, -$lines);

        return implode("\n", $slice);
    }

    public function logMtime(string $step): ?int
    {
        $path = $this->logPath($step);

        return is_file($path) ? (int) @filemtime($path) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isStaleRow(array $row): bool
    {
        if (($row['status'] ?? null) !== 'running') {
            return false;
        }

        $threshold = $this->staleSeconds();

        // Usa o mais recente entre mtime do log e updated_at como heartbeat: um
        // heartbeat() no banco conta mesmo se o log estiver parado, e vice-versa.
        $mtime = $this->logMtime((string) ($row['step'] ?? ''));
        $updated = $row['updated_at'] ?? null;
        $updatedTs = $updated === null
            ? null
            : (is_numeric($updated) ? (int) $updated : (int) strtotime((string) $updated));

        $beat = max((int) $mtime, (int) $updatedTs);
        if ($beat <= 0) {
            return false;
        }

        return (time() - $beat) > $threshold;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function safe(string $step): string
    {
        return preg_replace('/[^a-z0-9_-]/i', '_', $step) ?: 'step';
    }
}
