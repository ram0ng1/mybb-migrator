<?php

namespace Ramon\MybbMigrator\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\MybbMigrator\Gui\PhpBinaryLocator;
use Ramon\MybbMigrator\Gui\ProcessRunner;
use Ramon\MybbMigrator\Gui\StepCatalog;
use Ramon\MybbMigrator\Gui\StepStore;
use Ramon\MybbMigrator\MybbDatabase;

/**
 * Dispara um ou mais passos como processo CLI em background. Aceita:
 *  - { "sequence": "phase1" | "phase2" | "all" }
 *  - { "steps": ["groups","users",...] }
 *  - { "step": "content" }
 * + opcional { "extra": { "content": { "limit": 100 }, "users": { "dry-run": true } } }
 */
class RunStepController implements RequestHandlerInterface
{
    public function __construct(
        protected StepStore $store,
        protected ProcessRunner $runner,
        protected SettingsRepositoryInterface $settings,
        protected PhpBinaryLocator $locator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();

        // Sequência (Rodar Fase/tudo) vs passo avulso (botão Rodar de um card).
        // Numa sequência aplicamos RESUMO: passos já 'done' são pulados, então
        // reabrir o botão após corrigir uma falha continua de onde parou, sem
        // refazer o que já passou nem exigir rodar cada passo na mão. Um passo
        // avulso é sempre explícito (roda mesmo se já estava 'done').
        $isSequence = ! empty($body['sequence']) || (! empty($body['steps']) && is_array($body['steps']));

        $steps = $this->resolveSteps($body);
        if ($steps === []) {
            return new JsonResponse(['error' => 'no-steps'], 422);
        }

        $catalog = StepCatalog::indexed();
        foreach ($steps as $key) {
            if (! isset($catalog[$key])) {
                return new JsonResponse(['error' => 'unknown-step', 'step' => $key], 422);
            }
        }

        // Reconcilia mortos e impede execução concorrente.
        $this->store->clearStale();
        if (($running = $this->store->runningStep()) !== null) {
            return $this->fail(
                'already-running',
                "Já existe um passo em execução ({$running}). Aguarde terminar ou use Cancelar.",
                409
            );
        }

        // Usa o mesmo PHP do Flarum (ou o override informado). Validação com
        // -n (seguro): basta conseguir a versão para considerar executável.
        $override = (string) ($this->settings->get('mybb-migrator.php_binary') ?? '');
        $php = $this->locator->resolve($override);
        $check = $php === '' ? ['version' => null, 'error' => 'not-found'] : $this->locator->validate($php);
        if ($php === '' || $check['version'] === null) {
            // Inclui o caminho resolvido e o motivo (ex.: 'proc-open-disabled',
            // 'sapi:fpm-fcgi', 'not-executable') para diagnóstico — sob Docker o
            // PHP_BINARY costuma ser o php-fpm, que não roda como CLI.
            return $this->fail(
                'no-php-cli',
                "PHP CLI inválido (resolvido: " . ($php ?: '—') . ", motivo: " . ($check['error'] ?? 'desconhecido') . "). "
                . 'Defina o caminho do PHP na aba Conexão e Salve.'
            );
        }

        // Pré-checa a conexão MyBB com os settings JÁ SALVOS (que é o que o
        // processo CLI vai usar — não o que está digitado no form). Evita lançar
        // um processo em background fadado a falhar e mostra o erro na hora.
        $mybbHost = (string) ($this->settings->get('mybb_host') ?: '127.0.0.1');
        try {
            MybbDatabase::fromSettings($this->settings)->pdo()->query('SELECT 1');
        } catch (\Throwable $e) {
            return $this->fail(
                'mybb-unreachable',
                "Não foi possível conectar ao banco do MyBB salvo (host: {$mybbHost}). "
                . 'Na aba Conexão, confira os campos e clique em Salvar (o botão Testar não salva). '
                . 'Detalhe: ' . $e->getMessage()
            );
        }

        // Resumo: numa sequência, pula os passos já concluídos (status 'done').
        // Mantém a ordem; só passos avulsos forçam re-execução de um 'done'.
        $skippedDone = [];
        if ($isSequence) {
            $rows = $this->store->all();
            $skippedDone = array_values(array_filter(
                $steps,
                static fn ($k) => ($rows[$k]['status'] ?? null) === 'done'
            ));
            $steps = array_values(array_filter(
                $steps,
                static fn ($k) => ($rows[$k]['status'] ?? null) !== 'done'
            ));

            if ($steps === []) {
                // Tudo já concluído: nada a fazer (a UI já mostra tudo verde).
                return new JsonResponse(['ok' => true, 'steps' => [], 'note' => 'all-done'], 200);
            }
        }

        $extra = isset($body['extra']) && is_array($body['extra']) ? $body['extra'] : [];

        // Pré-marca o primeiro passo como running (guard imediato + feedback na UI).
        $this->store->ensureLogDir();
        $first = $steps[0];
        $this->store->markRunning($first, 0, $this->store->logPath($first));

        try {
            $this->runner->spawn($php, $steps, $extra);
        } catch (\Throwable $e) {
            $this->store->markFinished($first, 1, ['error' => 'spawn falhou: ' . $e->getMessage()]);
            return $this->fail('spawn-failed', 'Falha ao iniciar o processo de migração: ' . $e->getMessage(), 500);
        }

        return new JsonResponse(['ok' => true, 'steps' => $steps, 'skipped_done' => $skippedDone], 202);
    }

    /**
     * Erro no formato JSON:API ({errors:[{detail}]}) para que o front mostre a
     * mensagem (detail) diretamente no alerta, em vez de um texto genérico.
     */
    private function fail(string $code, string $detail, int $status = 422): JsonResponse
    {
        return new JsonResponse([
            'errors' => [[
                'status' => (string) $status,
                'code'   => $code,
                'detail' => $detail,
            ]],
        ], $status);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<int, string>
     */
    private function resolveSteps(array $body): array
    {
        if (! empty($body['sequence'])) {
            return StepCatalog::sequence((string) $body['sequence']);
        }
        if (! empty($body['steps']) && is_array($body['steps'])) {
            return array_values(array_filter(array_map('strval', $body['steps'])));
        }
        if (! empty($body['step'])) {
            return [(string) $body['step']];
        }

        return [];
    }
}
