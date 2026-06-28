<?php

namespace Ramon\MybbMigrator\Gui;

use Flarum\Foundation\Paths;

/**
 * Dispara `php flarum mybb:gui-run ...` como processo **destacado** (fire and
 * forget), para que a requisição HTTP retorne na hora e a migração rode ao
 * fundo. O próprio `gui-run` grava status/log; aqui só lançamos o processo.
 *
 * No Windows lançamos via um `.bat` que prefixa a pasta do PHP no PATH antes de
 * chamar o php.exe — isso garante que o `libssh2.dll`/`libssl` etc. que
 * acompanham aquela instalação sejam carregados (evita o diálogo "ponto de
 * entrada não encontrado" quando há uma DLL antiga noutro lugar do PATH).
 */
class ProcessRunner
{
    public function __construct(
        protected Paths $paths,
    ) {
    }

    /**
     * @param string $php   caminho do binário PHP já resolvido
     * @param array<int, string> $steps  chaves de passos (StepCatalog)
     * @param array<string, mixed> $extra opções por passo: [stepKey => [opt => val]]
     *
     * @throws \RuntimeException se o binário PHP estiver vazio
     */
    public function spawn(string $php, array $steps, array $extra = []): void
    {
        $php = trim($php);
        if ($php === '') {
            throw new \RuntimeException('no-php-cli');
        }

        $base = rtrim($this->paths->base, '/\\');
        $flarum = $base . DIRECTORY_SEPARATOR . 'flarum';
        $stepArg = implode(',', $steps);
        $extraB64 = base64_encode((string) json_encode($extra ?: (object) []));

        $log = $this->runnerLogPath();
        $dir = dirname($log);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $phpDir = dirname($php);

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->spawnWindows($php, $phpDir, $flarum, $base, $stepArg, $extraB64, $log, $dir);
        } else {
            $this->spawnUnix($php, $phpDir, $flarum, $base, $stepArg, $extraB64, $log);
        }
    }

    private function spawnWindows(
        string $php,
        string $phpDir,
        string $flarum,
        string $base,
        string $steps,
        string $extraB64,
        string $log,
        string $dir,
    ): void {
        // Um .bat resolve as aspas/PATH de forma confiável e roda destacado.
        $bat = $dir . DIRECTORY_SEPARATOR . '_run.bat';
        $lines = [
            '@echo off',
            'set "PATH=' . $phpDir . ';%PATH%"',
            'cd /d "' . $base . '"',
            '"' . $php . '" "' . $flarum . '" mybb:gui-run --steps=' . $steps
                . ' --extra-b64=' . $extraB64 . ' > "' . $log . '" 2>&1',
        ];
        @file_put_contents($bat, implode("\r\n", $lines) . "\r\n");

        // "start /B" desanexa e retorna na hora. Redireciona a saída do start
        // p/ NUL (o gui-run já escreve no log) e usa proc_open p/ não deixar um
        // pipe pendente (evita o aviso "pipe inexistente" no processo pai).
        $cmd = 'cmd /c start "" /B "' . $bat . '" >NUL 2>&1';
        $proc = @proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => false]);
        if (is_resource($proc)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            @proc_close($proc);
        }
    }

    private function spawnUnix(
        string $php,
        string $phpDir,
        string $flarum,
        string $base,
        string $steps,
        string $extraB64,
        string $log,
    ): void {
        $cmd = 'cd ' . escapeshellarg($base) . ' && PATH=' . escapeshellarg($phpDir) . ':"$PATH" '
            . 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($flarum)
            . ' mybb:gui-run --steps=' . escapeshellarg($steps)
            . ' --extra-b64=' . escapeshellarg($extraB64)
            . ' > ' . escapeshellarg($log) . ' 2>&1 &';

        @exec($cmd);
    }

    public function runnerLogPath(): string
    {
        return rtrim($this->paths->storage, '/\\')
            . DIRECTORY_SEPARATOR . 'logs'
            . DIRECTORY_SEPARATOR . 'mybb-migrator'
            . DIRECTORY_SEPARATOR . '_runner.log';
    }
}
