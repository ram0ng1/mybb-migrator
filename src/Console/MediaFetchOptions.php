<?php

namespace Ramon\MybbMigrator\Console;

use Flarum\Settings\SettingsRepositoryInterface;
use Ramon\MybbMigrator\Support\ExitPool;
use Ramon\MybbMigrator\Support\ImageFetcher;
use Ramon\MybbMigrator\Support\ImageOptimizer;
use Ramon\MybbMigrator\Support\ImgurClient;
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
     * @param bool $imgur   true só onde há URLs de imgur para resolver (imagens
     *                      de posts); anexos do MyBB nunca vêm de lá.
     */
    protected function addMediaFetchOptions(bool $network = true, bool $imgur = false): void
    {
        if ($network) {
            $this
                ->addOption('retries', null, InputOption::VALUE_REQUIRED, 'Retries for transient failures (HTTP 429/5xx, timeouts).')
                ->addOption('host-delay', null, InputOption::VALUE_REQUIRED, 'Minimum delay between requests to the same host, in milliseconds.')
                ->addOption('exit-ips', null, InputOption::VALUE_REQUIRED, 'Comma-separated exit IPs or proxies to rotate downloads over (overrides the panel list).')
                ->addOption('no-defer', null, InputOption::VALUE_NONE, 'Retry connection failures (DNS/connect) inline instead of deferring those URLs to the end of the run.');
        }

        if ($network && $imgur) {
            $this
                ->addOption('imgur-client-id', null, InputOption::VALUE_REQUIRED, 'imgur API Client-ID: resolves each image with one authenticated call instead of guessing extensions (overrides the panel setting).')
                ->addOption('imgur-daily-cap', null, InputOption::VALUE_REQUIRED, 'Maximum imgur API calls per UTC day, persisted across runs (default ' . ImgurClient::DEFAULT_DAILY_CAP . '; 0 = no cap).');
        }

        $this
            ->addOption('no-optimize', null, InputOption::VALUE_NONE, 'Store the bytes exactly as downloaded: no re-encoding, no resizing.')
            ->addOption('no-webp', null, InputOption::VALUE_NONE, 'Optimize without converting to WebP (keeps the original format).')
            ->addOption('quality', null, InputOption::VALUE_REQUIRED, 'Re-encoding quality, 30-100.')
            ->addOption('max-dim', null, InputOption::VALUE_REQUIRED, 'Resize images whose longest side exceeds this many pixels (0 = never resize).')
            ->addOption('min-gain', null, InputOption::VALUE_REQUIRED, 'Minimum size gain, in percent, for the re-encoded file to be kept (0 = keep whenever it is not bigger).');
    }

    protected function fetchRetries(SettingsRepositoryInterface $settings): int
    {
        return $this->mediaIntOpt('retries', (int) ($settings->get('mybb-migrator.image_retries') ?? 3));
    }

    protected function fetchHostDelay(SettingsRepositoryInterface $settings): int
    {
        return $this->mediaIntOpt('host-delay', (int) ($settings->get('mybb-migrator.image_host_delay') ?? 350));
    }

    /**
     * Lista de IPs/proxies de saída: `--exit-ips` manda, senão a aba Imagens.
     * Vazia = pool "direto", o comportamento de sempre.
     */
    protected function fetchExitPool(SettingsRepositoryInterface $settings): ExitPool
    {
        $raw = (string) ($this->input->getOption('exit-ips') ?? '');

        if (trim($raw) === '') {
            $raw = (string) ($settings->get('mybb-migrator.image_exit_ips') ?? '');
        }

        return ExitPool::fromList($raw);
    }

    /**
     * Cliente da API do imgur — ou null quando não há Client-ID (nem na opção,
     * nem no painel), caso em que o fetcher chuta extensões como sempre. A cota
     * do dia vive na tabela `settings`, para sobreviver entre runs e ser vista
     * pelo painel.
     */
    protected function imgurClient(SettingsRepositoryInterface $settings): ?ImgurClient
    {
        $hasOptions = $this->input->hasOption('imgur-client-id');

        $clientId = $hasOptions ? (string) ($this->input->getOption('imgur-client-id') ?? '') : '';
        if (trim($clientId) === '') {
            $clientId = (string) ($settings->get('mybb-migrator.imgur_client_id') ?? '');
        }
        if (trim($clientId) === '') {
            return null;
        }

        $cap = $hasOptions
            ? $this->mediaIntOpt('imgur-daily-cap', (int) ($settings->get('mybb-migrator.imgur_daily_cap') ?? ImgurClient::DEFAULT_DAILY_CAP))
            : (int) ($settings->get('mybb-migrator.imgur_daily_cap') ?? ImgurClient::DEFAULT_DAILY_CAP);

        return new ImgurClient(
            $clientId,
            $cap,
            $settings->get('mybb-migrator.imgur_quota'),
            fn (string $state) => $settings->set('mybb-migrator.imgur_quota', $state),
        );
    }

    /** Falhas de conexão adiadas para o fim do run? (padrão sim; --no-defer desliga) */
    protected function fetchDefers(): bool
    {
        return ! ($this->input->hasOption('no-defer') && $this->input->getOption('no-defer'));
    }

    protected function buildFetcher(SettingsRepositoryInterface $settings, int $timeout, int $maxBytes): ImageFetcher
    {
        $pool = $this->fetchExitPool($settings);

        return (new ImageFetcher(
            $timeout,
            $maxBytes,
            $this->fetchRetries($settings),
            $this->fetchHostDelay($settings),
            $pool,
        ))
            ->withImgur($this->imgurClient($settings))
            ->deferConnectionFailures($this->fetchDefers())
            ->onNotice(function (array $notice): void {
                // Uma linha só, no momento em que acontece: "o host X saiu do
                // ar" explica por que as URLs seguintes dele passam voando; "a
                // cota do imgur acabou" explica por que tudo do imgur virou
                // adiado de repente.
                $this->info($this->trans(match ($notice['kind']) {
                    'imgur_cap'        => 'common.notice_imgur_cap',
                    'imgur_remote_cap' => 'common.notice_imgur_remote_cap',
                    default            => 'common.notice_host_tripped',
                }, $notice));
            })
            ->onRetry(function (array $retry) use ($pool): void {
                // Sem esta linha, um 429 do imgur com backoff de 30 s parece o
                // comando ter travado — inclusive no console do painel, onde
                // não existe um Ctrl+C para ver se ainda há vida. Com rodízio
                // ligado, saber POR QUAL IP a tentativa saiu é metade do
                // diagnóstico.
                $this->info($this->trans(
                    $pool->rotates() ? 'common.retrying_via' : 'common.retrying',
                    $retry
                ));
            })
            ->onExitDown(function (array $down): void {
                $this->info($this->trans('common.exit_down', $down));
            });
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
            $this->mediaIntOpt('min-gain', (int) ($settings->get('mybb-migrator.image_min_gain') ?? 5)),
        );
    }

    /**
     * Linha única de log com o que foi decidido para a rede.
     * Depende do {@see TranslatesOutput} (todo comando que chama isto o usa).
     */
    protected function describeFetch(SettingsRepositoryInterface $settings, int $timeout): string
    {
        $summary = $this->trans('common.network_summary', [
            'retries' => $this->fetchRetries($settings),
            'delay'   => $this->fetchHostDelay($settings),
            'timeout' => $timeout,
        ]);

        // O rodízio só entra no resumo quando existe: "1 IP de saída (direto)"
        // seria uma linha a mais para dizer que nada mudou.
        $pool = $this->fetchExitPool($settings);
        if ($pool->rotates()) {
            $summary .= ' · ' . $this->trans('common.network_exits', [
                'count' => $pool->count(),
                'list'  => $pool->describe(),
            ]);
        }

        if ($this->fetchDefers()) {
            $summary .= ' · ' . $this->trans('common.network_defer');
        }

        // Idem para o imgur: só aparece quando há credencial, e aí diz quanto
        // da cota do dia já foi gasto — é a pergunta que o admin faz quando o
        // run começa a adiar tudo do imgur.
        $imgur = $this->imgurClient($settings);
        if ($imgur !== null) {
            $summary .= ' · ' . $this->trans($imgur->dailyCap() > 0 ? 'common.network_imgur' : 'common.network_imgur_uncapped', [
                'used' => $imgur->usedToday(),
                'cap'  => $imgur->dailyCap(),
            ]);
        }

        return $summary;
    }

    /**
     * Idem para a otimização. O ImageOptimizer é puro e não conhece tradutor —
     * ele expõe a configuração, a frase é montada aqui.
     */
    protected function describeOptimizer(ImageOptimizer $optimizer): string
    {
        if (! $optimizer->enabled()) {
            return $this->trans('common.optimizer_disabled');
        }

        if (! $optimizer->available()) {
            return $this->trans('common.optimizer_unavailable');
        }

        $format = match (true) {
            $optimizer->webpAvailable() => $this->trans('common.optimizer_format_webp'),
            $optimizer->wantsWebp()     => $this->trans('common.optimizer_format_source_no_webp'),
            default                     => $this->trans('common.optimizer_format_source'),
        };

        return $this->trans('common.optimizer_summary', [
            'format'  => $format,
            'quality' => $optimizer->quality(),
            'size'    => $optimizer->maxDimension() > 0
                ? $this->trans('common.optimizer_max_pixels', ['pixels' => $optimizer->maxDimension()])
                : $this->trans('common.optimizer_no_resize'),
            'gain'    => $optimizer->minGain(),
        ]);
    }

    private function mediaIntOpt(string $name, int $default): int
    {
        $value = $this->input->getOption($name);

        return $value === null || $value === '' ? $default : max(0, (int) $value);
    }
}
