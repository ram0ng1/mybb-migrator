<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('mybb_legacy_passwords', function (Blueprint $table) {
    $table->unsignedInteger('user_id')->primary();
    $table->string('algorithm', 32);
    $table->string('hash', 255);
    $table->string('salt', 16)->default('');

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
