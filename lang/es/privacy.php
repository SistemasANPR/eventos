<?php

declare(strict_types=1);

return [
    'body_required' => 'El aviso necesita texto en al menos un idioma.',
    'draft_already_open' => 'Este evento ya tiene un borrador de aviso abierto. Publícalo o edítalo antes de crear otro.',
    'published_is_immutable' => 'Una versión publicada no se puede editar. Crea una versión nueva a partir de ella.',
    'already_published' => 'Esta versión ya está publicada.',
    'default_language_required' => 'Para publicar, el aviso necesita su texto en el idioma por defecto del evento (:locale).',
    'relation_title' => 'Aviso de privacidad',
    'new_draft' => 'Nuevo borrador',
    'edit_draft' => 'Editar borrador',
    'empty_heading' => 'Este evento aún no tiene aviso de privacidad',
    'empty_description' => 'Crea un borrador, revísalo y publícalo. Sin un aviso publicado no se puede abrir una convocatoria.',
    'columns' => [
        'version' => 'Versión',
        'status' => 'Estado',
        'published_at' => 'Publicado (UTC)',
        'published_by' => 'Publicado por',
    ],
    'status' => [
        'draft' => 'Borrador',
        'published' => 'Publicado',
    ],
    'fields' => [
        'body_es' => 'Texto (español)',
        'body_en' => 'Texto (inglés)',
        'body_pt' => 'Texto (portugués)',
    ],
    'actions' => [
        'publish' => 'Publicar',
        'publish_confirm' => '¿Publicar esta versión? Quedará visible para el público y ya no se podrá editar; para cambiarla crearás una versión nueva.',
        'published' => 'Aviso publicado',
        'saved' => 'Borrador guardado',
        'failed' => 'No se pudo completar la acción',
    ],
];
