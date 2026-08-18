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

        // --- Aba Imagens: filtro de hosts + orçamento padrão de um run ---
        if (array_key_exists('image_hosts', $body)) {
            $this->settings->set('mybb-migrator.image_hosts', $this->normalizeHosts((string) $body['image_hosts']));
        }
        if (array_key_exists('attachments_dir', $body)) {
            $this->settings->set('mybb-migrator.attachments_dir', trim((string) $body['attachments_dir']));
        }
        $numeric = [
            'image_limit', 'image_max_mb', 'image_max_file_mb',
            // Otimização + ritmo de rede (ver ImageOptimizer / ImageFetcher).
            'image_quality', 'image_max_dim', 'image_host_delay', 'image_retries',
        ];
        foreach ($numeric as $field) {
            if (array_key_exists($field, $body)) {
                $this->settings->set('mybb-migrator.' . $field, (string) max(0, (int) $body[$field]));
            }
        }

        foreach (['image_optimize', 'image_webp'] as $field) {
            if (array_key_exists($field, $body)) {
                $value = $body[$field];
                $on = $value === true || $value === 1 || $value === '1' || $value === 'true';
                $this->settings->set('mybb-migrator.' . $field, $on ? '1' : '0');
            }
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

    /**
     * O admin digita o que for mais natural — uma URL completa, um host, vários
     * separados por vírgula/quebra de linha. Guardamos uma lista canônica,
     * separada por vírgula, e reduzimos "https://i.imgur.com/" a "i.imgur.com"
     * (URL sem caminho = host), que é o que o filtro do comando compara.
     */
    private function normalizeHosts(string $raw): string
    {
        $out = [];

        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $entry) {
            $entry = strtolower(trim((string) $entry));
            if ($entry === '') {
                continue;
            }

            if (str_contains($entry, '://')) {
                $path = (string) parse_url($entry, PHP_URL_PATH);
                $entry = ($path === '' || $path === '/')
                    ? (string) parse_url($entry, PHP_URL_HOST)
                    : rtrim($entry, '/');
            }

            if ($entry !== '' && ! in_array($entry, $out, true)) {
                $out[] = $entry;
            }
        }

        return implode(',', $out);
    }
}
