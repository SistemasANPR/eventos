<?php

declare(strict_types=1);

/* Conflicto de interés y revisión ciega del panel del evaluador (plan 011, F5). */
return [
    'conflict' => [
        'action' => 'Declarar conflicto',
        'heading' => 'Declarar conflicto de interés',
        'description' => 'Si conoces a los autores o tienes un interés en esta propuesta, decláralo: ya no podrás calificarla ni descargar sus archivos y el equipo organizador verá el aviso para asignarla a otra persona. No se puede deshacer desde este panel.',
        'confirm' => 'Declarar conflicto',
        'declared' => 'Conflicto declarado. El equipo organizador verá el aviso.',
        'not_allowed' => 'No se pudo declarar el conflicto: la asignación ya se envió, se canceló o ya estaba declarada. Si ya enviaste tu evaluación, pide al equipo organizador que cancele la asignación.',
    ],

    'blind' => [
        'notice' => 'Revisión ciega: los datos de los autores y los campos que los identifican no se muestran en esta evaluación.',
    ],

    'organizer' => [
        'conflicts' => 'Conflictos declarados: :count',
    ],
];
