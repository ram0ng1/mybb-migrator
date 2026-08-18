<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Settings\SettingsRepositoryInterface;
use Ramon\MybbMigrator\Support\ImageFetcher;
use Ramon\MybbMigrator\Support\ImageOptimizer;
use Symfony\Component\Console\Input\InputOption;

/**
 * Opções compartilhadas por `mybb:images` e `mybb:attachments`, cobrindo as duas
 * dores de uma internalização de mídia de verdade.
 *
 * RITMO DE REDE. O acervo de um fórum antigo concentra centenas de imagens num
 * punhado de hosts (i.imgur.com, i.postimg.cc). Baixar tudo o mais rápido
 * possível é o jeito garantido de levar `HTTP 429` em bloco e de estourar
 * timeout em CDN lenta. `--host-delay` espaça as requisições ao MESMO host e
 * `--retries` define quantas vezes uma falha transitória volta a ser tentada
 * (com backoff, respeitando `Retry-After`). O trabalho pesado está no
 * ImageFetcher; aqui ficam só os botões.
 *
 * PESO EM DISCO. Imagem de 2010 é JPEG de câmera: 3000 px e 2 MB para ser
 * exibida a 700 px. `--max-dim` e a conversão para WebP cortam tipicamente 80-90 %
 * disso, e a hora de fazer é a da importação — depois de migrado, mudar o
 * formato das imagens obriga a reescrever o XML de milhares de posts.
 */
trait MediaFetchOptions
{
    /**
     * @param bool $network false num comando que só mexe no que já está em
     *                      disco: expor --retries/--host-delay ali seria oferecer
     *                      botão que não liga em nada.
     */
    protected function addMediaFetchOptions(bool $network = true): void
    {
        if ($network) {
            $this
                ->addOption('retries', null, InputOption::VALUE_REQUIRED, 'Retries for transient failures (HTTP 429/5xx, timeouts).')
                ->addOption('host-delay', null, InputOption::VALUE_REQUIRED, 'Minimum delay between requests to the same host, in milliseconds.');
        }

        $this
            ->addOption('no-optimize', null, InputOption::VALUE_NONE, 'Store the bytes exactly as downloaded: no re-encoding, no resizing.')
            ->addOption('no-webp', null, InputOption::VALUE_NONE, 'Optimize without converting to WebP (keeps the original format).')
            ->addOption('quality', null, InputOption::VALUE_REQUIRED, 'Re-encoding quality, 30-100.')
            ->addOption('max-dim', null, InputOption::VALUE_REQUIRED, 'Resize images whose longest side exceeds this many pixels (0 = never resize).');
    }

    protected function fetchRetries(SettingsRepositoryInterface $settings): int
    {
        return $this->mediaIntOpt('retries', (int) ($settings->get('mybb-migrator.image_retries') ?? 3));
    }

    protected function fetchHostDelay(SettingsRepositoryInterface $settings): int
    {
        return $this->mediaIntOpt('host-delay', (int) ($settings->get('mybb-migrator.image_host_delay') ?? 350));
    }

    protected function buildFetcher(SettingsRepositoryInterface $settings, int $timeout, int $maxBytes): ImageFetcher
    {
        return new ImageFetcher(
            $timeout,
            $maxBytes,
            $this->fetchRetries($settings),
            $this->fetchHostDelay($settings),
        );
    }

    /**
     * Otimizador com as opções do run — ou os padrões da aba "Imagens".
     * Desligar é sempre possível (`--no-optimize`), e qualquer dúvida dentro do
     * ImageOptimizer devolve os bytes originais.
     */
    protected function buildOptimizer(SettingsRepositoryInterface $settings): ImageOptimizer
    {
        $enabled = ! $this->input->getOption('no-optimize')
            && (string) ($settings->get('mybb-migrator.image_optimize') ?? '1') !== '0';

        $webp = ! $this->input->getOption('no-webp')
            && (string) ($settings->get('mybb-migrator.image_webp') ?? '1') !== '0';

        return new ImageOptimizer(
            $enabled,
            $webp,
            $this->mediaIntOpt('quality', (int) ($settings->get('mybb-migrator.image_quality') ?: 82)),
            $this->mediaIntOpt('max-dim', (int) ($settings->get('mybb-migrator.image_max_dim') ?? 1600)),
        );
    }

    /** Linha única de log com o que foi decidido para a rede. */
    protected function describeFetch(SettingsRepositoryInterface $settings, int $timeout): string
    {
        return sprintf(
            '%d retentativa(s) em falha transitoria, %d ms entre requisicoes ao mesmo host, %ds sem tráfego para desistir',
            $this->fetchRetries($settings),
            $this->fetchHostDelay($settings),
            $timeout
        );
    }

    private function mediaIntOpt(string $name, int $default): int
    {
        $value = $this->input->getOption($name);

        return $value === null || $value === '' ? $default : max(0, (int) $value);
    }
}
