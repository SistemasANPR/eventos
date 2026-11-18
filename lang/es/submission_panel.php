<?php

declare(strict_types=1);

return [
    'navigation' => 'Propuestas',
    'model' => 'propuesta',
    'plural' => 'propuestas',
    'empty' => 'Todavía no hay propuestas en este evento',

    'columns' => [
        'title' => 'Título',
        'call' => 'Convocatoria',
        'type' => 'Categoría',
        'track' => 'Tema',
        'status' => 'Estado',
        'submitted_at' => 'Enviada',
        'activity' => 'Actividad',
    ],

    'filters' => [
        'range' => 'Fecha de envío',
        'from' => 'Desde',
        'until' => 'Hasta',
        'show_accepted' => 'Mostrar también las aceptadas',
        'invalid' => 'El filtro no es válido. Revisa los valores e inténtalo de nuevo.',
    ],

    'detail' => [
        'general' => 'Datos generales',
        'answers' => 'Respuestas',
        'participants' => 'Participantes',
        'primary' => 'Principal',
        'retired' => 'campo retirado del formulario',
        'yes' => 'Sí',
        'no' => 'No',
        'no_answers' => 'Esta propuesta no tiene respuestas.',
        'no_participants' => 'Esta propuesta no tiene participantes.',
        'files_attached' => '{0} Sin archivos adjuntos|{1} :count archivo adjunto|[2,*] :count archivos adjuntos',
    ],

    'summary' => [
        'action' => 'Resumen',
        'total' => 'Total de propuestas de la convocatoria: :count',
        'by_track' => 'Por tema',
        'by_type' => 'Por categoría',
        'by_day' => 'Por día de envío',
        'empty' => 'Todavía no hay propuestas de la convocatoria en este evento, así que no hay nada que resumir. Cuando lleguen, aquí verás cuántas hay por tema, por categoría y por día.',
    ],

    'export' => [
        'action' => 'Exportar',
        'format' => 'Formato',
        'format_invalid' => 'El formato de exportación no es válido.',
        'description' => 'Se exportan las propuestas con los filtros que tienes aplicados. El archivo se prepara en segundo plano y queda disponible 24 horas en «Exportaciones recientes».',
        'queued_title' => 'Exportación en curso',
        'queued_body' => 'Cuando el archivo esté listo aparecerá en «Exportaciones recientes».',
        'too_large' => 'La exportación tiene :rows propuestas y el máximo es :max. Afina los filtros (convocatoria, tema, categoría, estado o fechas) e inténtalo de nuevo.',
        'unavailable' => 'El archivo de la exportación ya no está disponible. Pide una nueva exportación.',
        'recent' => 'Exportaciones recientes',
        'recent_empty' => 'No hay exportaciones de las últimas 24 horas.',
        'recent_row' => ':date · :rows propuestas · :format',
        'download' => 'Descargar',
        'recent_note' => 'Los enlaces de descarga caducan a los 5 minutos y solo sirven a quien pidió la exportación.',
    ],
];
