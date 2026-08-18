<?php

namespace Ramon\MybbMigrator\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\MybbMigrator\Gui\MediaDetector;

/**
 * Preenche sozinho as configurações da aba Imagens: rankeia os hosts de imagem
 * realmente usados pelos posts migrados e localiza a pasta `uploads` do MyBB.
 *
 * Fica num endpoint próprio (e não no /status, que roda em polling) porque a
 * varredura custa ~1s: ela acontece quando o painel abre com as configurações
 * ainda vazias, ou quando o admin pede explicitamente uma redetecção.
 */
class DetectMediaController implements RequestHandlerInterface
{
    public function __construct(
        protected MediaDetector $detector,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();

        // `apply=false` deixa só espiar o resultado sem gravar nada.
        $apply = ! array_key_exists('apply', $body) || (bool) $body['apply'];
        $scan = isset($body['scan']) ? max(100, (int) $body['scan']) : MediaDetector::SCAN_POSTS;

        return new JsonResponse($this->detector->detect($apply, $scan));
    }
}
