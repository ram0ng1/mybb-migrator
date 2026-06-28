<?php

namespace Ramon\MybbMigrator\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\MybbMigrator\Gui\PhpBinaryLocator;
use Ramon\MybbMigrator\MybbDatabase;

/**
 * Testa a conexão ao banco MyBB e valida o binário PHP CLI. Aceita valores
 * avulsos no corpo (para testar ANTES de salvar) ou cai nas configurações
 * já gravadas.
 */
class TestConnectionController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected PhpBinaryLocator $locator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();
        $g = fn (string $field, string $key, $default) => array_key_exists($field, $body) && $body[$field] !== ''
            ? $body[$field]
            : ($this->settings->get($key) ?: $default);

        $host   = (string) $g('host', 'mybb_host', '127.0.0.1');
        $port   = (int) $g('port', 'mybb_port', 3306);
        $user   = (string) $g('user', 'mybb_user', 'root');
        $db     = (string) $g('db', 'mybb_db', 'mybb');
        $prefix = (string) $g('prefix', 'mybb_prefix', 'mybb_');
        // senha: do corpo se enviada, senão a salva
        $password = array_key_exists('password', $body) && (string) $body['password'] !== ''
            ? (string) $body['password']
            : (string) ($this->settings->get('mybb_password') ?? '');

        $result = ['ok' => false, 'error' => null, 'counts' => [], 'php' => $this->phpInfo($body)];

        try {
            $mybb = new MybbDatabase($host, $user, $password, $db, $prefix, $port);
            foreach (['users' => 'users', 'threads' => 'threads', 'posts' => 'posts', 'forums' => 'forums'] as $label => $table) {
                try {
                    $result['counts'][$label] = (int) $mybb->scalar('SELECT COUNT(*) FROM ' . $mybb->table($table));
                } catch (\Throwable $e) {
                    // tabela ausente — segue
                }
            }
            $result['ok'] = array_key_exists('users', $result['counts']);
            if (! $result['ok']) {
                $result['error'] = 'Conectou, mas a tabela "' . $prefix . 'users" não foi encontrada (confira o prefixo).';
            }
        } catch (\Throwable $e) {
            $result['ok'] = false;
            $result['error'] = $e->getMessage();
        }

        return new JsonResponse($result);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function phpInfo(array $body): array
    {
        $override = array_key_exists('php_binary', $body) && trim((string) $body['php_binary']) !== ''
            ? trim((string) $body['php_binary'])
            : (string) ($this->settings->get('mybb-migrator.php_binary') ?? '');

        $resolved = $this->locator->resolve($override);
        if ($resolved === '') {
            return ['ok' => false, 'version' => null, 'resolved' => '', 'autodetected' => $override === ''];
        }

        $v = $this->locator->validate($resolved);

        return [
            'ok'           => (bool) $v['ok'],
            'version'      => $v['version'],
            'resolved'     => $resolved,
            'autodetected' => $override === '',
            'error'        => $v['error'] ?? null,
        ];
    }
}
