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
            return new JsonResponse(['error' => 'already-running', 'running' => $running], 409);
        }

        // Usa o mesmo PHP do Flarum (ou o override informado). Validação com
        // -n (seguro): basta conseguir a versão para considerar executável.
        $override = (string) ($this->settings->get('mybb-migrator.php_binary') ?? '');
        $php = $this->locator->resolve($override);
        if ($php === '' || $this->locator->validate($php)['version'] === null) {
            return new JsonResponse(['error' => 'no-php-cli'], 422);
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
            return new JsonResponse(['error' => 'spawn-failed', 'message' => $e->getMessage()], 500);
        }

        return new JsonResponse(['ok' => true, 'steps' => $steps], 202);
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
