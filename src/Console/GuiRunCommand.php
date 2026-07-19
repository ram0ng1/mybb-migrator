<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Console\AbstractCommand;
use Ramon\MybbMigrator\Gui\StepCatalog;
use Ramon\MybbMigrator\Gui\StepStore;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Orquestrador usado pelo painel de admin. NÃO reimplementa migração: para cada
 * passo, localiza o comando `mybb:*` existente e o executa via
 * Application->find()->run(), gravando saída em log por passo e status na tabela
 * `mybb_migration_steps`. Assim a migração roda exatamente igual ao CLI.
 *
 * Rodado em processo destacado por {@see \Ramon\MybbMigrator\Gui\ProcessRunner}.
 */
class GuiRunCommand extends AbstractCommand
{
    public function __construct(
        protected StepStore $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('mybb:gui-run')
            ->setDescription('[interno] Executa passos de migração para o painel de admin, gravando status/log.')
            ->addOption('steps', null, InputOption::VALUE_REQUIRED, 'Chaves de passo separadas por vírgula (StepCatalog).')
            ->addOption('extra-b64', null, InputOption::VALUE_REQUIRED, 'JSON base64 de opções por passo: {step:{opt:val}}.', '');
    }

    protected function fire(): int
    {
        $steps = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->input->getOption('steps'))
        )));

        if ($steps === []) {
            $this->error('Nenhum passo informado (--steps).');
            return 1;
        }

        $extra = $this->decodeExtra((string) $this->input->getOption('extra-b64'));
        $catalog = StepCatalog::indexed();
        $this->store->ensureLogDir();

        $failed = false;

        foreach ($steps as $key) {
            $def = $catalog[$key] ?? null;

            if ($def === null) {
                $this->info("Passo desconhecido, ignorado: {$key}");
                continue;
            }

            if ($failed) {
                // Regra de ouro do README: parar na primeira falha de uma sequência.
                $this->store->markSkipped($key);
                continue;
            }

            $code = $this->runStep($def, $extra[$key] ?? []);
            if ($code !== 0) {
                $failed = true;
            }
        }

        return $failed ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $def
     * @param array<string, mixed> $opts
     */
    private function runStep(array $def, array $opts): int
    {
        $key = $def['key'];
        $logPath = $this->store->logPath($key);
        $this->store->markRunning($key, (int) getmypid(), $logPath);

        $handle = @fopen($logPath, 'w');
        if ($handle === false) {
            $this->store->markFinished($key, 1, ['error' => "não foi possível abrir o log: {$logPath}"]);
            return 1;
        }
        // escrita sem buffer → o painel consegue ler o tail ao vivo
        @stream_set_write_buffer($handle, 0);
        $out = new StreamOutput($handle, OutputInterface::VERBOSITY_NORMAL, false);

        $header = sprintf("==> %s  (%s)\n", $def['command'], date('Y-m-d H:i:s'));
        $out->write($header);

        $code = 1;
        try {
            $command = $this->getApplication()->find($def['command']);
            $input = new ArrayInput($this->buildArgs($def, $opts));
            $input->setInteractive(false);
            $code = $command->run($input, $out);
        } catch (\Throwable $e) {
            $out->writeln("\n<error>EXCEÇÃO: " . $e->getMessage() . "</error>");
            $out->writeln($e->getTraceAsString());
            $code = 1;
        }

        $out->write(sprintf("\n<== exit %d  (%s)\n", $code, date('Y-m-d H:i:s')));
        @fclose($handle);

        $this->store->markFinished($key, (int) $code, $this->parseSummary($logPath));

        return (int) $code;
    }

    /**
     * Monta as opções para o ArrayInput: --force quando aplicável + opções extras
     * que o passo realmente suporta (whitelist do catálogo).
     *
     * @param array<string, mixed> $def
     * @param array<string, mixed> $opts
     * @return array<string, mixed>
     */
    private function buildArgs(array $def, array $opts): array
    {
        $args = [];
        if (! empty($def['force'])) {
            $args['--force'] = true;
        }

        // Flags fixos do passo (ex.: --passwords-only): sempre passados, sem UI.
        foreach ((array) ($def['fixedArgs'] ?? []) as $flag) {
            $args['--' . $flag] = true;
        }

        $allowed = (array) ($def['options'] ?? []);
        foreach ($opts as $name => $val) {
            if (! in_array($name, $allowed, true)) {
                continue;
            }
            if ($val === true || $val === 'true' || $val === 1 || $val === '1') {
                $args['--' . $name] = true;
            } elseif ($val === false || $val === null || $val === '' || $val === 'false') {
                continue;
            } else {
                $args['--' . $name] = (string) $val;
            }
        }

        return $args;
    }

    /**
     * Extrai um resumo da saída: pares "rótulo: número" e as últimas linhas.
     *
     * @return array<string, mixed>
     */
    private function parseSummary(string $logPath): array
    {
        $content = (string) @file_get_contents($logPath);
        $lines = preg_split("/\r\n|\n|\r/", trim($content)) ?: [];

        $counts = [];
        $warnings = [];
        foreach ($lines as $line) {
            $clean = trim(preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? '');

            // Avisos: linhas marcadas com ⚠ pelos comandos (itens pulados etc.).
            // O passo segue (exit 0); só queremos exibi-los ao final, sem parar.
            if (preg_match('/^⚠\s*(.+)$/u', $clean, $mw)) {
                if (count($warnings) < 50) {
                    $warnings[] = trim($mw[1]);
                }
                continue;
            }

            if (preg_match('/^([\p{L}\p{N} +\/().,_-]+?)\s*[:=]\s*([\d.,]+)\s*$/u', $clean, $m)) {
                $label = trim($m[1]);
                $num = (int) str_replace(['.', ','], '', $m[2]);
                $counts[$label] = $num;
            }
        }

        $nonEmpty = array_values(array_filter(array_map('trim', $lines), static fn ($l) => $l !== ''));

        return [
            'counts'   => $counts,
            'warnings' => $warnings,
            'tail'     => array_slice($nonEmpty, -12),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function decodeExtra(string $b64): array
    {
        if (trim($b64) === '') {
            return [];
        }
        $json = base64_decode($b64, true);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }
}
