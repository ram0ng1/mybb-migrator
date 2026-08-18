<?php

use Flarum\Database\Migration;

/**
 * Progresso ao vivo de um passo em execução.
 *
 * O painel já mostra o log em tempo real, mas log não responde "falta quanto?".
 * Passos longos que percorrem uma coleção conhecida (hoje `mybb:images` e
 * `mybb:attachments`) publicam aqui quantos itens já processaram — e o total,
 * quando é barato de saber (uma discussão específica, por exemplo).
 *
 * `progress_total` NULO significa deliberadamente "total desconhecido": varrer
 * 273 mil posts para contar antes de começar custaria mais que o trabalho em si,
 * então a barra fica indeterminada em vez de mentir uma porcentagem.
 */
return Migration::addColumns('mybb_migration_steps', [
    // `addColumn` recebe o tipo base do Blueprint; "unsigned" é atributo, não
    // tipo (não existe typeUnsignedInteger na gramática do MySQL).
    'progress_done'  => ['integer', 'unsigned' => true, 'nullable' => true],
    'progress_total' => ['integer', 'unsigned' => true, 'nullable' => true],
    'progress_label' => ['string', 'length' => 191, 'nullable' => true],
]);
