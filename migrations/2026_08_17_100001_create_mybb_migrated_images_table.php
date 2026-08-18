<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Mapa "URL remota -> arquivo local" das imagens/anexos baixados por
 * `mybb:images` e `mybb:attachments`.
 *
 * Serve a três propósitos:
 *  1. IDEMPOTÊNCIA — uma URL já baixada nunca é baixada de novo; o comando só
 *     reaponta o conteúdo dos posts (é o "pular links já populados").
 *  2. MEMÓRIA DE FALHA — imagens mortas (404, imgur removed.png, HTML no lugar
 *     do binário) ficam registradas como `failed` e não são retentadas a cada
 *     execução, a menos que se use --retry-failed. Falha TRANSITÓRIA (HTTP 429,
 *     5xx, timeout) é outra coisa: fica como `deferred` e volta sozinha na
 *     execução seguinte — o arquivo existe, o host é que recusou o ritmo.
 *  3. RE-APLICAÇÃO — se `mybb:rebuild-formatting` regenerar os posts a partir do
 *     MyBB (voltando às URLs remotas), rodar `mybb:images --relink-only` reaponta
 *     tudo em segundos, sem rede.
 *
 * A chave é o sha1 da URL de origem (URLs passam de 191 chars e não cabem num
 * índice único do MySQL com utf8mb4).
 */
return Migration::createTable('mybb_migrated_images', function (Blueprint $table) {
    $table->increments('id');
    $table->char('url_hash', 40)->unique();
    $table->text('source_url');
    $table->string('local_name', 191)->nullable();
    $table->text('local_url')->nullable();
    $table->unsignedBigInteger('size')->nullable();
    $table->string('mime', 100)->nullable();
    $table->string('kind', 16)->default('image');   // image|attachment
    $table->string('status', 16)->default('ok');    // ok|failed|deferred
    $table->text('error')->nullable();
    $table->unsignedInteger('posts')->default(0);   // posts reapontados
    $table->unsignedInteger('file_id')->nullable(); // fof_upload_files.id, se existir
    $table->timestamp('created_at')->nullable();

    $table->index('status');
});
