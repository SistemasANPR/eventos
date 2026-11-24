<?php

declare(strict_types=1);

/*
 * Privacidad (plan 014, F4). La sal con que se calcula la huella no reversible de las personas
 * anonimizadas (`anonymization_log`). A la sal se le añade el identificador de la organización: la misma
 * persona deja huellas distintas en dos organizaciones. Es un secreto de la plataforma: sin ella no se
 * puede anonimizar (la huella es lo que permite reaplicar la anonimización tras restaurar un respaldo).
 * Cambiarla invalida la huella de todas las anonimizaciones registradas: ver `docs/operacion.md`.
 */
return [
    'anonymization_salt' => env('ANONYMIZATION_SALT'),
];
