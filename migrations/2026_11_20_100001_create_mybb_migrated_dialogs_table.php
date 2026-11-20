<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * O que `mybb:messages` criou no flarum/messages e no ernestdefoe/pigeonhole.
 *
 * Por que existe: as tabelas do flarum/messages não têm onde marcar a origem de
 * uma conversa, e a migração precisa ser re-executável sem nunca apagar o que os
 * membros escreveram depois no Flarum. Re-executar apaga e recria SÓ as conversas
 * listadas aqui — e mesmo dessas, só as que ninguém tocou desde então (o
 * `last_message_id` gravado bate com o da conversa e não há reações nem
 * respostas). Uma conversa migrada que já recebeu mensagem nova fica intocada.
 *
 * As pastas guardam o par (usuário, pasta do MyBB) para a re-execução reusar a
 * pasta já criada em vez de duplicá-la — e para não desfazer o que o membro
 * arquivou nela à mão.
 *
 * As FKs só são criadas quando as tabelas de destino já existem (a extensão de
 * destino pode ser ativada depois desta); o comando trata linhas órfãs de
 * qualquer forma.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('mybb_migrated_dialogs')) {
            $schema->create('mybb_migrated_dialogs', function (Blueprint $table) use ($schema) {
                $table->unsignedInteger('dialog_id')->primary();
                // "direct:2,3" ou "group:<sha1 de participantes|assunto>".
                $table->string('conversation_key', 80)->unique();
                $table->unsignedInteger('last_message_id')->nullable();
                $table->unsignedInteger('message_count')->default(0);
                $table->dateTime('created_at')->nullable();

                if ($schema->hasTable('dialogs')) {
                    $table->foreign('dialog_id')->references('id')->on('dialogs')->cascadeOnDelete();
                }
            });
        }

        if (! $schema->hasTable('mybb_migrated_pm_folders')) {
            $schema->create('mybb_migrated_pm_folders', function (Blueprint $table) use ($schema) {
                $table->unsignedInteger('folder_id')->primary();
                $table->unsignedInteger('user_id');
                // fid da pasta personalizada no MyBB (5, 6, ...).
                $table->unsignedSmallInteger('mybb_folder_id');

                $table->unique(['user_id', 'mybb_folder_id']);

                if ($schema->hasTable('pigeonhole_folders')) {
                    $table->foreign('folder_id')->references('id')->on('pigeonhole_folders')->cascadeOnDelete();
                }
            });
        }
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('mybb_migrated_pm_folders');
        $schema->dropIfExists('mybb_migrated_dialogs');
    },
];
