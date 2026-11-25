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

    'bulk' => [
        'accept' => 'Aceptar seleccionadas',
        'waitlist' => 'Lista de espera (seleccionadas)',
        'reject' => 'Rechazar seleccionadas',
        'filtered' => 'Decidir sobre el filtro',
        'filtered_description' => 'Decide sobre todas las propuestas que la lista muestra con los filtros aplicados (máximo :max por operación). Las que no pueden pasar al estado elegido se omiten y se informan.',
        'apply' => 'Decidir',
        'to' => 'Decisión',
        'pick_target' => 'Elige la decisión para ver cuántas propuestas se afectarán.',
        'comment_help' => 'Texto plano; el mismo comentario se guarda en todas las propuestas y se envía al avisar el resultado.',
        'preview' => 'Se decidirán :count propuesta(s) como «:to» (:by_status).',
        'preview_omitted' => 'Se omitirán :count que no pueden pasar a ese estado.',
        'preview_notice' => 'Todas se deciden en una sola operación y se pueden revertir por lote. El autor no recibe correo hasta que se publiquen los resultados de la convocatoria.',
        'done_title' => 'Decisión masiva aplicada',
        'done' => ':count propuesta(s) pasan a «:to».',
        'published_now' => 'La convocatoria ya había publicado sus resultados: se avisó a :count autor(es) al decidir.',
        'omitted' => 'Se omitieron :count propuesta(s) que no pueden pasar a «:to» (:by_status).',
        'revert_batch' => 'Revertir lote',
        'revert_batch_description' => 'Devuelve a revisión las propuestas de una decisión masiva. Las que ya cambiaron de estado después no se tocan y se informan.',
        'batch' => 'Lote',
        'batch_summary' => ':count propuesta(s) → «:to»',
        'revert_title' => 'Reversa del lote',
        'reverted' => ':count propuesta(s) vuelven a revisión.',
        'reverted_skipped' => 'Se omitieron :count propuesta(s) que ya no están en el estado que dejó el lote:',
        'more' => 'y :count más.',
        'skip_reasons' => [
            'status_changed' => 'su estado cambió después del lote',
        ],
        'errors' => [
            'invalid_target' => 'Elige una decisión válida.',
            'none_eligible' => 'Ninguna de las propuestas puede pasar a «:to».',
            'not_eligible' => ':count propuesta(s) no pueden pasar a «:to» y no se cambió nada: :list',
            'and_more' => 'y :count más',
        ],
    ],

    'publish' => [
        'action' => 'Publicar resultados',
        'description' => 'Fija los resultados de la convocatoria como públicos y avisa por correo a cada autor con una propuesta decidida. Las decisiones posteriores se avisan al decidir.',
        'submit' => 'Publicar y avisar',
        'call' => 'Convocatoria',
        'pick_call' => 'Elige la convocatoria para ver a cuántos autores se avisará.',
        'nothing_pending' => 'No hay resultados pendientes de avisar.',
        'preview' => 'Se avisará a :count autor(es) (:by_status).',
        'results_at' => 'Fecha de resultados prevista: :date.',
        'already' => 'Esta convocatoria ya había publicado sus resultados.',
        'title' => 'Resultados publicados',
        'done' => 'Se avisó a :count autor(es).',
        'failed' => 'No se pudo avisar a :count autor(es): quedan pendientes y se reintentan al volver a publicar.',
    ],
];
