<?php

namespace Ramon\MybbMigrator\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\MybbMigrator\Gui\PhpBinaryLocator;

/**
 * Grava a conexão MyBB nas MESMAS chaves usadas pelos comandos CLI
 * (mybb_host/port/user/password/db/prefix) + o caminho do PHP CLI usado para
 * disparar os processos em background.
 */
class SaveConnectionController implements RequestHandlerInterface
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

        $map = [
            'host'   => 'mybb_host',
            'port'   => 'mybb_port',
            'user'   => 'mybb_user',
            'db'     => 'mybb_db',
            'prefix' => 'mybb_prefix',
        ];
        foreach ($map as $field => $key) {
            if (array_key_exists($field, $body)) {
                $this->settings->set($key, (string) $body[$field]);
            }
        }

        // Senha: só grava quando enviada (campo em branco não apaga a existente).
        if (array_key_exists('password', $body) && (string) $body['password'] !== '') {
            $this->settings->set('mybb_password', (string) $body['password']);
        }

        // URL do site antigo (para a aba Comparar).
        if (array_key_exists('old_site_url', $body)) {
            $this->settings->set('mybb-migrator.old_site_url', rtrim(trim((string) $body['old_site_url']), '/'));
        }

        $phpInfo = null;
        if (array_key_exists('php_binary', $body)) {
            $php = trim((string) $body['php_binary']);
            $this->settings->set('mybb-migrator.php_binary', $php);
            if ($php !== '') {
                $phpInfo = $this->locator->validate($php);
            }
        }

        return new JsonResponse(['ok' => true, 'php' => $phpInfo]);
    }
}
