<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Estado de execução de cada passo da migração, alimentado pelo comando
 * `mybb:gui-run` e lido pelo painel de admin (polling). Uma linha por passo
 * (chave = nome curto do comando, ex.: "content", "fix-charset").
 */
return Migration::createTable('mybb_migration_steps', function (Blueprint $table) {
    $table->string('step', 64)->primary();
    $table->string('status', 16)->default('pending'); // pending|running|done|failed|skipped
    $table->unsignedInteger('pid')->nullable();
    $table->integer('exit_code')->nullable();
    $table->text('summary')->nullable();   // JSON: contagens/linhas-resumo extraídas da saída
    $table->string('log_path', 255)->nullable();
    $table->timestamp('started_at')->nullable();
    $table->timestamp('finished_at')->nullable();
    $table->timestamp('updated_at')->nullable(); // heartbeat
});
