<?php

namespace Ramon\MybbMigrator\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\MybbMigrator\Gui\StepCatalog;
use Ramon\MybbMigrator\Gui\StepStore;

/**
 * Tail do log de um passo específico (para expandir o console de qualquer passo,
 * não só o que está em execução).
 */
class LogController implements RequestHandlerInterface
{
    public function __construct(
        protected StepStore $store,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $step = (string) ($request->getQueryParams()['step'] ?? '');
        if (! StepCatalog::find($step)) {
            return new JsonResponse(['error' => 'unknown-step', 'step' => $step], 422);
        }

        $lines = (int) ($request->getQueryParams()['lines'] ?? 500);
        $lines = max(50, min(5000, $lines));

        return new JsonResponse([
            'step' => $step,
            'log'  => $this->store->tail($step, $lines),
        ]);
    }
}
