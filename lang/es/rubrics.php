<?php

declare(strict_types=1);

return [
    'navigation' => 'Rúbricas',
    'model' => 'rúbrica',
    'plural' => 'rúbricas',
    'heading' => 'Rúbricas de evaluación',
    'create_title' => 'Nueva rúbrica',
    'edit_title' => 'Editar rúbrica',

    'sections' => [
        'general' => 'Datos de la rúbrica',
        'criteria' => 'Criterios',
    ],
    'status' => [
        'draft' => 'Borrador',
        'published' => 'Publicada',
    ],
    'kinds' => [
        'scale' => 'Calificación',
        'text' => 'Comentario',
    ],
    'table' => [
        'submission_type' => 'Tipo de propuesta',
        'version' => 'Versión',
        'status' => 'Estado',
        'blind_review' => 'Revisión ciega',
        'min_reviewers' => 'Evaluadores mínimos',
        'criteria' => 'Criterios',
        'yes' => 'Sí',
        'no' => 'No',
        'empty_heading' => 'Aún no hay rúbricas',
        'empty_description' => 'Crea una rúbrica por tipo de propuesta y publícala para que los evaluadores puedan calificar.',
    ],
    'fields' => [
        'submission_type' => 'Tipo de propuesta',
        'min_reviewers' => 'Evaluadores mínimos por propuesta',
        'min_reviewers_help' => 'Cuántas evaluaciones necesita cada propuesta para considerarse cubierta.',
        'discrepancy_threshold' => 'Umbral de discrepancia',
        'discrepancy_threshold_help' => 'Diferencia, en puntos de la escala, desde la que dos evaluaciones se marcan como discrepantes. Debe ser mayor que cero y no mayor que la amplitud de la escala más corta.',
        'blind_review' => 'Revisión ciega',
        'blind_review_help' => 'El evaluador no ve a quien envía la propuesta.',
        'criteria' => 'Criterios',
        'criteria_help' => 'Un criterio de calificación se promedia con su peso; uno de comentario es texto libre y no se promedia. La rúbrica necesita al menos un criterio de calificación para publicarse.',
        'add_criterion' => 'Agregar criterio',
        'untitled' => 'Criterio sin etiqueta',
        'label_es' => 'Etiqueta (español)',
        'label_en' => 'Etiqueta (inglés)',
        'label_pt' => 'Etiqueta (portugués)',
        'kind' => 'Tipo de criterio',
        'weight' => 'Peso',
        'weight_help' => 'Mayor que cero.',
        'scale_min' => 'Escala: mínimo',
        'scale_max' => 'Escala: máximo',
    ],
    'blind' => [
        'warning' => 'La revisión ciega no oculta todo automáticamente.',
        'warning_detail' => 'El título y los campos de texto libre no se filtran, salvo los que marques como «identifica al autor» en el formulario de propuestas.',
        'marked_fields' => 'Campos marcados hoy en los formularios de este evento: :fields.',
        'no_marked_fields' => 'Ningún campo de los formularios de este evento está marcado como «identifica al autor».',
        'saved_warning' => 'Recuerda: con revisión ciega, el título y los campos de texto libre no se ocultan salvo los marcados como «identifica al autor».',
    ],
    'versioning' => [
        'notice' => 'Esta rúbrica ya tiene evaluaciones.',
        'notice_detail' => 'Al guardar se creará una versión nueva en borrador; la versión actual queda intacta con sus evaluaciones.',
    ],
    'actions' => [
        'create' => 'Nueva rúbrica',
        'edit' => 'Editar',
        'publish' => 'Publicar',
        'publish_help' => 'La rúbrica publicada pasa a ser la vigente de su tipo de propuesta y se revalida antes de publicarse.',
        'published' => 'Rúbrica publicada.',
        'new_version' => 'Nueva versión',
        'saved_new_version' => 'Se creó la versión :version en borrador; la anterior quedó intacta.',
        'back' => 'Volver a rúbricas',
    ],
];
