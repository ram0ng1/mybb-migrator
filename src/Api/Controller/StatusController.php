<?php

namespace Ramon\MybbMigrator\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\MybbMigrator\Gui\MigrationSnapshot;
use Ramon\MybbMigrator\Gui\PhpBinaryLocator;
use Ramon\MybbMigrator\Gui\StepCatalog;
use Ramon\MybbMigrator\Gui\StepStore;

/**
 * Status do painel (polling). Leve por padrão: estados dos passos + log do passo
 * em execução + pré-checagem. Inclui contagens origem/destino apenas com
 * `?counts=1` (são COUNT(*) custosos, então o front pede só sob demanda).
 */
class StatusController implements RequestHandlerInterface
{
    public function __construct(
        protected StepStore $store,
        protected SettingsRepositoryInterface $settings,
        protected MigrationSnapshot $snapshot,
        protected PhpBinaryLocator $locator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        // Reconcilia linhas "running" cujo processo morreu (sem heartbeat).
        $this->store->clearStale();

        $running = $this->store->runningStep();
        $rows = $this->store->all();

        $steps = [];
        foreach (StepCatalog::all() as $def) {
            $key = $def['key'];
            $row = $rows[$key] ?? null;
            $steps[$key] = [
                'status'      => $row['status'] ?? 'pending',
                'exit_code'   => $row['exit_code'] ?? null,
                'summary'     => isset($row['summary']) ? json_decode((string) $row['summary'], true) : null,
                'started_at'  => $row['started_at'] ?? null,
                'finished_at' => $row['finished_at'] ?? null,
                'stale'       => $row ? $this->store->isStaleRow($row) : false,
            ];
        }

        $data = [
            'connection' => $this->connectionMeta(),
            'preflight'  => $this->snapshot->preflight(),
            'running'    => $running,
            'runningLog' => $running ? $this->store->tail($running, 200) : '',
            'steps'      => $steps,
            'catalog'    => StepCatalog::all(),
        ];

        if (! empty($request->getQueryParams()['counts'])) {
            $data['source'] = $this->snapshot->source();
            $data['target'] = $this->snapshot->target();
        }

        return new JsonResponse($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionMeta(): array
    {
        // Sem executar php aqui (polling): só resolvemos o caminho. A validação
        // (versão) acontece na ação "Testar", iniciada pelo admin.
        $php = (string) ($this->settings->get('mybb-migrator.php_binary') ?? '');
        $resolved = $this->locator->resolve($php);

        return [
            'host'             => (string) ($this->settings->get('mybb_host') ?: '127.0.0.1'),
            'port'             => (int) ($this->settings->get('mybb_port') ?: 3306),
            'user'             => (string) ($this->settings->get('mybb_user') ?: 'root'),
            'db'               => (string) ($this->settings->get('mybb_db') ?: 'mybb'),
            'prefix'           => (string) ($this->settings->get('mybb_prefix') ?: 'mybb_'),
            'old_site_url'     => (string) ($this->settings->get('mybb-migrator.old_site_url') ?? ''),
            'password_set'     => ((string) ($this->settings->get('mybb_password') ?? '')) !== '',
            'php_binary'       => $php,
            'php_resolved'     => $resolved,
            'php_autodetected' => $php === '',
            'php_valid'        => null, // desconhecido até "Testar"
            'php_version'      => null,
        ];
    }
}
