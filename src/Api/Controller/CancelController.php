<?php

namespace Ramon\MybbMigrator\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\MybbMigrator\Gui\StepCatalog;
use Ramon\MybbMigrator\Gui\StepStore;

/**
 * Ações de controle:
 *  - { "action": "cancel" }            mata o processo do passo em execução (best-effort) e o marca falho
 *  - { "action": "reset" }             limpa o status de TODOS os passos (volta a pending)
 *  - { "action": "reset", "step": x }  limpa o status de um passo
 */
class CancelController implements RequestHandlerInterface
{
    public function __construct(
        protected StepStore $store,
        protected ConnectionInterface $db,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? 'cancel');

        if ($action === 'reset') {
            $q = $this->db->table('mybb_migration_steps');
            if (! empty($body['step'])) {
                $key = (string) $body['step'];
                if (! StepCatalog::find($key)) {
                    return new JsonResponse(['error' => 'unknown-step', 'step' => $key], 422);
                }
                $q->where('step', $key)->delete();
            } else {
                $q->delete();
            }

            return new JsonResponse(['ok' => true]);
        }

        // cancel
        $running = $this->store->runningStep();
        $rows = $this->store->all();
        $killed = false;

        foreach ($rows as $key => $row) {
            if (($row['status'] ?? null) === 'running') {
                $pid = (int) ($row['pid'] ?? 0);
                if ($pid > 0) {
                    $killed = $this->kill($pid) || $killed;
                }
                $this->db->table('mybb_migration_steps')->where('step', $key)->update([
                    'status'      => 'failed',
                    'finished_at' => date('Y-m-d H:i:s'),
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]);
            }
        }

        return new JsonResponse(['ok' => true, 'running' => $running, 'killed' => $killed]);
    }

    private function kill(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            @exec('taskkill /F /T /PID ' . $pid . ' 2>&1', $o, $code);

            return $code === 0;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 15);
        }
        @exec('kill -TERM ' . $pid . ' 2>&1', $o, $code);

        return $code === 0;
    }
}
