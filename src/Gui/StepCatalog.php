<?php

namespace Ramon\MybbMigrator\Gui;

/**
 * Fonte única da verdade dos passos expostos no painel de admin.
 *
 * Cada passo mapeia para um comando `mybb:*` JÁ EXISTENTE — o painel apenas o
 * orquestra (via {@see \Ramon\MybbMigrator\Console\GuiRunCommand}), então a
 * migração roda exatamente igual à execução por CLI. A ordem e as dependências
 * seguem o README §4.
 *
 * Estrutura de cada item:
 *  - key:        nome curto (sem "mybb:"), usado como chave de status e na URL
 *  - command:    nome completo do comando Symfony
 *  - phase:      '0' | '1' | '2' | '3' | 'diag'
 *  - force:      passa --force ao executar (todos menos test-bio-render)
 *  - dangerous:  pede confirmação no painel (wipe / revert-* destrutivos)
 *  - options:    opções extras suportadas pelo comando, expostas na UI
 *                (dry-run, limit, username, ...) — ver addOption() de cada um
 */
class StepCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            // ---- Fase 0: preparação (destrutiva) ----
            self::s('wipe', 'mybb:wipe', '0', dangerous: true),

            // ---- Fase 1: núcleo (a ordem importa) ----
            self::s('groups',      'mybb:groups',      '1'),
            self::s('users',       'mybb:users',       '1', options: ['dry-run', 'limit']),
            self::s('avatars',     'mybb:avatars',     '1', options: ['skip-file-check']),
            self::s('tags',        'mybb:tags',        '1'),
            self::s('content',     'mybb:content',     '1', options: ['limit', 'skip-soft-deleted']),
            self::s('likes',       'mybb:likes',       '1', options: ['recover-likers']),
            self::s('permissions', 'mybb:permissions', '1'),
            self::s('forum-perms', 'mybb:forum-perms', '1'),

            // ---- Fase 2: conteúdo secundário ----
            self::s('subscriptions',  'mybb:subscriptions',  '2'),
            self::s('messages',       'mybb:messages',       '2', options: ['dry-run', 'limit']),
            self::s('polls',          'mybb:polls',          '2', options: ['dry-run']),
            self::s('trade-feedback', 'mybb:trade-feedback', '2'),
            self::s('reviews',        'mybb:reviews',        '2'),
            self::s('make-admin',     'mybb:make-admin',     '2', options: ['username', 'like'], requiresUsername: true, manual: true),

            // ---- Fase 3: limpeza / fidelidade (rodar só o que precisar) ----
            self::s('fix-charset',          'mybb:fix-charset',          '3'),
            self::s('fix-smilies',          'mybb:fix-smilies',          '3'),
            self::s('fix-emojis',           'mybb:fix-emojis',           '3'),
            self::s('fix-tapatalk-emoji',   'mybb:fix-tapatalk-emoji',   '3'),
            self::s('normalize-bbcode',     'mybb:normalize-bbcode',     '3'),
            self::s('fix-size-bbcode',      'mybb:fix-size-bbcode',      '3'),
            self::s('fix-font-bbcode',      'mybb:fix-font-bbcode',      '3'),
            self::s('strip-orphan-bbcode',  'mybb:strip-orphan-bbcode',  '3'),
            self::s('rebuild-formatting',   'mybb:rebuild-formatting',   '3', options: ['dry-run']),
            self::s('fix-spacing',          'mybb:fix-spacing',          '3', options: ['dry-run']),
            self::s('fix-pseudo-lists',     'mybb:fix-pseudo-lists',     '3', options: ['dry-run']),
            self::s('revert-md-strike-sub', 'mybb:revert-md-strike-sub', '3', dangerous: true),
            self::s('revert-ispoiler',      'mybb:revert-ispoiler',      '3', dangerous: true),
            self::s('fix-quotes',           'mybb:fix-quotes',           '3'),
            self::s('restore-quotes',       'mybb:restore-quotes',       '3'),
            self::s('compact-quotes',       'mybb:compact-quotes',       '3'),
            self::s('restore-quote-mentions', 'mybb:restore-quote-mentions', '3'),
            self::s('revert-quote-mentions',  'mybb:revert-quote-mentions',  '3', dangerous: true),
            self::s('fix-user-mentions',    'mybb:fix-user-mentions',    '3'),
            self::s('fix-mention-slugs',    'mybb:fix-mention-slugs',    '3'),
            self::s('fix-signatures',       'mybb:fix-signatures',       '3'),
            self::s('reimport-signatures',  'mybb:reimport-signatures',  '3'),
            self::s('migrate-signatures',   'mybb:migrate-signatures',   '3'),
            self::s('fix-usernames',        'mybb:fix-usernames',        '3', options: ['dry-run']),
            self::s('apply-nicknames',      'mybb:apply-nicknames',      '3', options: ['dry-run']),
            self::s('fix-discussion-slugs', 'mybb:fix-discussion-slugs', '3', options: ['dry-run']),
            self::s('fix-pm-parse',         'mybb:fix-pm-parse',         '3'),
            self::s('recover-protected',    'mybb:recover-protected',    '3'),

            // ---- Diagnóstico ----
            self::s('test-credentials', 'mybb:test-credentials', 'diag', options: ['username']),
        ];
    }

    /**
     * @return array<string, array<string, mixed>> indexado por key
     */
    public static function indexed(): array
    {
        $out = [];
        foreach (self::all() as $step) {
            $out[$step['key']] = $step;
        }

        return $out;
    }

    public static function find(string $key): ?array
    {
        return self::indexed()[$key] ?? null;
    }

    /**
     * Sequências guiadas (chaves em ordem). `all` = fase 1 + 2. Passos marcados
     * como `manual` (ex.: make-admin, que exige um --username específico) ficam
     * de fora — assim como wipe (fase 0) e os passos de limpeza (fase 3). Eles
     * continuam visíveis para execução individual no painel.
     *
     * @return array<int, string>
     */
    public static function sequence(string $name): array
    {
        $keys = static function (string $phase): array {
            return array_values(array_map(
                static fn ($s) => $s['key'],
                array_filter(
                    self::all(),
                    static fn ($s) => $s['phase'] === $phase && empty($s['manual'])
                )
            ));
        };

        return match ($name) {
            'phase1' => $keys('1'),
            'phase2' => $keys('2'),
            'all'    => array_merge($keys('1'), $keys('2')),
            default  => [],
        };
    }

    /**
     * @param array<int, string> $options
     */
    private static function s(
        string $key,
        string $command,
        string $phase,
        bool $force = true,
        bool $dangerous = false,
        array $options = [],
        bool $requiresUsername = false,
        bool $manual = false,
    ): array {
        return [
            'key'              => $key,
            'command'          => $command,
            'phase'            => $phase,
            'force'            => $force,
            'dangerous'        => $dangerous,
            'options'          => $options,
            'requiresUsername' => $requiresUsername,
            'manual'           => $manual,
        ];
    }
}
