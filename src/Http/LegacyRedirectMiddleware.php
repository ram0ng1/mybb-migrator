<?php

declare(strict_types=1);

namespace Ramon\MybbMigrator\Http;

use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\RequestUtil;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Redireciona (301) as URLs do MyBB antigo para os endereços do Flarum, para
 * que links externos, favoritos e o Google não caiam em 404 depois da troca.
 * O formato de cada URL está em LegacyUrl; aqui o id vira endereço.
 *
 * O destino respeita o que o visitante pode ver: o slug (que carrega o título)
 * só entra quando o ator vê o tópico, a tag ou o usuário. Um tópico oculto
 * vira `/d/{id}` (o Flarum responde como responderia a qualquer link direto),
 * e uma tag ou usuário que o ator não vê segue para o 404 normal, sem expor
 * nome nenhum no cabeçalho Location.
 *
 * Qualquer outra requisição passa direto, sem consulta ao banco.
 */
class LegacyRedirectMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected ConnectionInterface $db,
        protected UrlGenerator $url,
        protected SlugManager $slugs,
        protected ExtensionManager $extensions
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }

        $target = LegacyUrl::parse($request->getUri()->getPath(), $request->getQueryParams());

        if ($target === null) {
            return $handler->handle($request);
        }

        try {
            $location = $this->resolve($target[0], $target[1], RequestUtil::getActor($request));
        } catch (\Throwable $e) {
            error_log('[mybb-migrator] redirect: '.$e->getMessage());
            $location = null;
        }

        return $location === null ? $handler->handle($request) : new RedirectResponse($location, 301);
    }

    private function resolve(string $type, int $id, User $actor): ?string
    {
        return match ($type) {
            LegacyUrl::POST        => $this->post($id, $actor),
            LegacyUrl::THREAD      => $this->discussion($id, $actor, null),
            LegacyUrl::THREAD_LAST => $this->discussion($id, $actor, 'last'),
            LegacyUrl::FORUM       => $this->tag($id, $actor),
            LegacyUrl::USER        => $this->user($id, $actor),
            default                => null,
        };
    }

    private function post(int $id, User $actor): ?string
    {
        $row = $this->db->table('posts')->where('id', $id)->first(['discussion_id', 'number']);

        if ($row === null) {
            return null;
        }

        return $this->discussion((int) $row->discussion_id, $actor, $row->number === null ? null : (string) $row->number);
    }

    private function discussion(int $id, User $actor, ?string $near): ?string
    {
        if (! $this->db->table('discussions')->where('id', $id)->exists()) {
            return null;
        }

        $discussion = Discussion::whereVisibleTo($actor)->find($id);
        $idSlug = $discussion instanceof Discussion
            ? $this->slugs->forResource(Discussion::class)->toSlug($discussion)
            : (string) $id;

        if ($near === 'last') {
            $last = $discussion instanceof Discussion
                ? ($discussion->last_post_number ?: $this->db->table('posts')->where('discussion_id', $id)->max('number'))
                : null;
            $near = $last ? (string) $last : null;
        }

        $params = ['id' => $idSlug];
        if ($near !== null && $near !== '1') {
            $params['near'] = $near;
        }

        return $this->url->to('forum')->route('discussion', $params);
    }

    private function tag(int $id, User $actor): ?string
    {
        if (! $this->extensions->isEnabled('flarum-tags') || ! class_exists(\Flarum\Tags\Tag::class)) {
            return null;
        }

        $tag = \Flarum\Tags\Tag::whereVisibleTo($actor)->find($id);

        return $tag === null ? null : $this->url->to('forum')->route('tag', ['slug' => $tag->slug]);
    }

    private function user(int $id, User $actor): ?string
    {
        $user = User::whereVisibleTo($actor)->find($id);

        return $user instanceof User
            ? $this->url->to('forum')->route('user', ['username' => $this->slugs->forResource(User::class)->toSlug($user)])
            : null;
    }
}
