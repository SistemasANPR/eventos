<?php

declare(strict_types=1);

return [
    'errors' => [
        'invalid_transition' => 'No se puede pasar una propuesta de «:from» a «:to».',
        'status_conflict' => 'Otra persona cambió el estado de esta propuesta antes que tú. Recarga la página y revisa su estado actual.',
        'confirmation_required' => 'Esta decisión ya se publicó y el autor ya fue avisado. Confirma de forma explícita que quieres revertirla.',
    ],

    'author_comment' => [
        'label' => 'Comentario para el autor (opcional)',
        'help' => 'Texto plano; no se interpreta como HTML ni como Markdown.',
        'max' => 'El comentario no puede tener más de :max caracteres.',
    ],

    'actions' => [
        'accept' => 'Aceptar',
        'waitlist' => 'Lista de espera',
        'reject' => 'Rechazar',
        'revert' => 'Revertir decisión',
        'accepted' => 'Propuesta aceptada.',
        'waitlisted' => 'Propuesta en lista de espera.',
        'rejected' => 'Propuesta rechazada.',
        'reverted' => 'Decisión revertida: la propuesta vuelve a revisión.',
        'failed' => 'No se pudo cambiar el estado',
        'revert_confirm' => 'La propuesta volverá a «En revisión» y se conserva el historial.',
        'revert_published_warning' => 'El resultado de esta propuesta ya se publicó: el autor ya fue avisado. Revertir no envía ningún correo.',
        'confirm_published' => 'Entiendo que el autor ya fue avisado',
        'reason' => 'Motivo (opcional, interno)',
    ],

    'history' => [
        'heading' => 'Historial de estados',
        'empty' => 'Todavía no hay cambios de estado.',
        'columns' => [
            'when' => 'Cuándo',
            'change' => 'Cambio',
            'actor' => 'Quién',
            'comment' => 'Comentario para el autor',
        ],
        'system' => 'Sistema',
    ],
];
