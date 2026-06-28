<?php

namespace Ramon\MybbMigrator\Gui;

/**
 * Resolve qual binário PHP usar para rodar `php flarum ...` em background.
 *
 * Por padrão usa o MESMO PHP que está executando o Flarum (a constante
 * `PHP_BINARY` do processo web) — assim a CLI usa a mesma instalação, o mesmo
 * `php.ini` e as mesmas extensões do site. Sob Apache/FastCGI o `PHP_BINARY`
 * aponta para o SAPI web (php-cgi); nesse caso usamos o `php.exe` irmão na mesma
 * pasta (mesma instalação/config). Não há varredura nem escolha de versão.
 *
 * O admin pode opcionalmente fixar um caminho (override) no painel.
 */
class PhpBinaryLocator
{
    /** Caminho do PHP a usar: override se informado, senão o PHP do Flarum. */
    public function resolve(string $override = ''): string
    {
        $override = trim($override);
        if ($override !== '') {
            return $override;
        }

        return $this->fromRunningPhp();
    }

    /** Diretório do binário (para prefixar no PATH ao executar). */
    public function dirOf(string $php): string
    {
        return dirname($php);
    }

    /**
     * Valida um binário rodando "<path> -n -v". O `-n` ignora o php.ini (não
     * carrega extensões), então é seguro chamar mesmo do contexto web sem
     * disparar diálogos de DLL ausente (ex.: curl/libssh2) no Windows.
     *
     * @return array{ok: bool, version: ?string, sapi: ?string, path: string, error: ?string}
     */
    public function validate(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            return ['ok' => false, 'version' => null, 'sapi' => null, 'path' => $path, 'error' => 'empty'];
        }

        // Sem proc_open não há como validar NEM lançar a migração. Em hospedagem
        // gerenciada / alguns containers ele está em disable_functions (e aí
        // function_exists() já devolve false). Reporta distintamente.
        if (! $this->canSpawn()) {
            return ['ok' => false, 'version' => null, 'sapi' => null, 'path' => $path, 'error' => 'proc-open-disabled'];
        }

        $out = $this->capture([$path, '-n', '-r', 'echo PHP_VERSION . "|" . PHP_SAPI;']);
        if ($out === null) {
            return ['ok' => false, 'version' => null, 'sapi' => null, 'path' => $path, 'error' => 'not-executable'];
        }

        [$version, $sapi] = array_pad(explode('|', trim($out), 2), 2, '');
        $version = trim($version);
        $sapi = trim($sapi);

        if (! preg_match('/^\d+\.\d+(\.\d+)?/', $version)) {
            return ['ok' => false, 'version' => null, 'sapi' => null, 'path' => $path, 'error' => 'unexpected-output'];
        }

        $isCli = $sapi === 'cli' || $sapi === 'phpdbg';

        return [
            'ok'      => $isCli,
            'version' => $version,
            'sapi'    => $sapi,
            'path'    => $path,
            'error'   => $isCli ? null : "sapi:{$sapi}",
        ];
    }

    private function fromRunningPhp(): string
    {
        $exe = DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php';
        $binary = (string) (defined('PHP_BINARY') ? PHP_BINARY : '');

        // Candidatos ao CLI, do mais específico ao mais comum. Importante para
        // Docker/php-fpm: PHP_BINARY é o php-fpm (em .../sbin), que NÃO roda `-r`;
        // o CLI de verdade fica em PHP_BINDIR (.../bin/php) na mesma instalação.
        $candidates = [];
        if ($binary !== '') {
            $candidates[] = dirname($binary) . DIRECTORY_SEPARATOR . $exe;
        }
        if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
            $candidates[] = rtrim((string) PHP_BINDIR, '/\\') . DIRECTORY_SEPARATOR . $exe;
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            // imagens oficiais (Debian/Alpine) e distros comuns
            $candidates[] = '/usr/local/bin/' . $exe;
            $candidates[] = '/usr/bin/' . $exe;
        }

        // Só checagem de caminho aqui (sem executar): resolve() é chamado a cada
        // polling de status. A validação (rodar o binário) fica no validate().
        foreach ($candidates as $cand) {
            if ($cand !== '' && @is_file($cand) && ! $this->looksLikeNonCli($cand)) {
                return $cand;
            }
        }

        // Último recurso: o próprio PHP_BINARY (pode já ser o CLI; se for php-fpm,
        // o validate() do chamador reporta o motivo — ex.: sapi:fpm-fcgi).
        return $binary;
    }

    /** Heurística barata: php-fpm / php-cgi não rodam scripts como o CLI. */
    private function looksLikeNonCli(string $path): bool
    {
        $name = strtolower(basename($path));

        return str_contains($name, 'fpm') || str_contains($name, 'cgi');
    }

    /** proc_open disponível? (false também quando está em disable_functions). */
    public function canSpawn(): bool
    {
        return function_exists('proc_open');
    }

    /**
     * @param array<int, string> $cmd
     */
    private function capture(array $cmd): ?string
    {
        if (! $this->canSpawn()) {
            return null;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $options = DIRECTORY_SEPARATOR === '\\' ? ['bypass_shell' => true] : [];

        $proc = @proc_open($cmd, $descriptors, $pipes, null, null, $options);
        if (! is_resource($proc)) {
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0 && trim((string) $stdout) === '') {
            return null;
        }

        return (string) $stdout;
    }
}
