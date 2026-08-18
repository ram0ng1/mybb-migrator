<?php

use Illuminate\Database\Schema\Builder;

/**
 * Reclassifica como `deferred` as URLs que ficaram gravadas como `failed` por
 * um motivo TRANSITÓRIO.
 *
 * Antes de o ImageFetcher distinguir os dois casos, um `HTTP 429` do imgur ou um
 * timeout do postimg.cc entrava no mapa exatamente como um 404: a URL era dada
 * como morta e nunca mais tentada sem `--retry-failed`. Só que essas imagens
 * existem — o host apenas recusou o ritmo do run.
 *
 * Esta migração conserta o histórico: as linhas cujo erro tem cara de rede
 * voltam para a fila e a próxima execução de `mybb:images` / `mybb:attachments`
 * as tenta de novo sozinha, agora com espaçamento por host e backoff.
 *
 * O que NÃO é mexido: 404, "imagem removida", HTML no lugar do binário, tipo não
 * suportado. Esses continuam `failed`, que é a verdade sobre eles.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('mybb_migrated_images')) {
            return;
        }

        $schema->getConnection()
            ->table('mybb_migrated_images')
            ->where('status', 'failed')
            ->where(function ($query) {
                foreach ([
                    '%HTTP 429%',      // rate limit
                    '%HTTP 408%',
                    '%HTTP 5__%',      // 500/502/503/504...
                    '%curl:%',         // timeout, conexão recusada, TLS
                    '%timed out%',
                    '%resposta vazia%',
                    '%não foi possível abrir a URL%',
                ] as $pattern) {
                    $query->orWhere('error', 'like', $pattern);
                }
            })
            ->update(['status' => 'deferred']);
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasTable('mybb_migrated_images')) {
            return;
        }

        $schema->getConnection()
            ->table('mybb_migrated_images')
            ->where('status', 'deferred')
            ->update(['status' => 'failed']);
    },
];
