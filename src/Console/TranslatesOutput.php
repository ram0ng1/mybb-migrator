<?php

namespace Ramon\MybbMigrator\Console;

use Symfony\Component\Console\Input\InputOption;

/**
 * Saída de comando TRADUZIDA, pelo mesmo caminho do resto da extensão:
 * `locale/en.yml` e `locale/pt-BR.yml`, sob a chave `ramon-mybb-migrator.cli`.
 *
 * Por que isto existe: a saída destes comandos não é só de terminal — o console
 * do painel de admin mostra exatamente estas linhas. Deixá-las cravadas no
 * código significava um painel bilíngue (interface traduzida, log em português
 * fixo) e nenhuma forma de mudar isso sem editar PHP.
 *
 * Qual idioma sai:
 *   1. `--locale=xx` no comando, quando passado;
 *   2. senão a configuração `mybb-migrator.cli_locale` (aba Conexão);
 *   3. senão o `default_locale` do fórum — e `en` como último recurso, que é o
 *      fallback do próprio tradutor do Flarum.
 *
 * O comando que usa este trait precisa de duas coisas: `$this->locales`
 * (injetado no construtor) e, para o item 2, `$this->settings`.
 *
 * O tipo injetado é o LocaleManager, e NÃO o TranslatorInterface, por um motivo
 * que custa meia hora para descobrir: os arquivos de tradução das extensões só
 * entram no tradutor quando alguém RESOLVE o LocaleManager no container (o
 * Extend\Locales registra um `resolving()`). Pedindo o tradutor direto, ele
 * chega sem catálogo nenhum e todo `trans()` devolve a própria chave.
 */
trait TranslatesOutput
{
    protected function addLocaleOption(): void
    {
        $this->addOption(
            'locale',
            null,
            InputOption::VALUE_REQUIRED,
            'Language for this command output (e.g. pt-BR). Defaults to the panel setting, then to the forum default_locale.'
        );
    }

    /**
     * Fixa o idioma da execução. Chamar no início do fire(), antes da primeira
     * linha impressa.
     */
    protected function applyLocale(): void
    {
        $locale = trim((string) ($this->input->getOption('locale') ?? ''));

        if ($locale === '' && isset($this->settings)) {
            $locale = trim((string) ($this->settings->get('mybb-migrator.cli_locale') ?? ''));
        }

        // Idioma sem pacote instalado não vira saída vazia: mantemos o padrão
        // do fórum em vez de cair num catálogo que não existe.
        if ($locale !== '' && $this->locales->hasLocale($locale)) {
            $this->locales->setLocale($locale);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function trans(string $key, array $params = []): string
    {
        return $this->locales->getTranslator()->trans('ramon-mybb-migrator.cli.' . $key, $params);
    }

    /**
     * Linha de relatório: rótulo traduzido, alinhado, e o valor.
     *
     * O alinhamento é calculado em runtime de propósito — "imagens candidatas" e
     * "candidate images" não têm o mesmo comprimento, e uma coluna cravada com
     * espaços no código só ficaria certa num idioma.
     *
     * @param array<string, mixed> $params
     */
    protected function stat(string $key, int|float|string $value, array $params = []): void
    {
        $label = $this->trans($key, $params);
        $pad = max(0, 26 - mb_strlen($label));

        $this->info('  ' . $label . str_repeat(' ', $pad) . ': ' . $value);
    }
}
