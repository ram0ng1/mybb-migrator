<?php

/**
 * Bootstrap mínimo para os testes de lógica pura (Charset, MybbPassword).
 * Não sobe o Flarum: carrega apenas as classes sem dependências de framework,
 * conforme a disciplina de testes da §60.8 do playbook.
 */

require __DIR__ . '/../src/Support/Charset.php';
require __DIR__ . '/../src/Support/MybbPassword.php';
require __DIR__ . '/../src/Support/TapatalkEmoji.php';
require __DIR__ . '/../src/Support/ImageFetcher.php';
require __DIR__ . '/../src/Console/PollOptionsParser.php';
require __DIR__ . '/../src/Console/MessagesGrouping.php';
require __DIR__ . '/../src/BBCode/Converter.php';
