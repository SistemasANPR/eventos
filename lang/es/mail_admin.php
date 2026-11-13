<?php

declare(strict_types=1);

/*
 * Pantallas del panel para los correos de la organización (plan 008, H-008-3, H-008-4 y
 * H-008-5): catálogo con estado, edición de plantillas y desactivar no críticas.
 */
return [
    'navigation' => 'Correos',
    'catalog' => [
        'title' => 'Correos del sistema',
        'subtitle' => 'Estos son todos los correos que envía la plataforma. Los críticos no se pueden desactivar.',
        'empty' => 'No hay notificaciones registradas.',
        'columns' => [
            'name' => 'Correo',
            'recipient' => 'Se envía a',
            'critical' => 'Crítico',
            'status' => 'Estado',
            'languages' => 'Personalizado en',
        ],
        'critical_yes' => 'Crítico',
        'critical_no' => 'Opcional',
        'no_languages' => '—',
    ],
    'status' => [
        'default' => 'Predeterminado',
        'customized' => 'Personalizado',
        'disabled' => 'Desactivado',
    ],
    'actions' => [
        'edit' => 'Ver o editar',
        'view' => 'Ver',
        'disable' => 'Desactivar',
        'enable' => 'Activar',
        'restore' => 'Restaurar predeterminado',
        'save' => 'Guardar plantilla',
        'preview' => 'Vista previa',
        'back' => 'Volver al catálogo',
        'close' => 'Cerrar',
    ],
    'confirm' => [
        'disable_heading' => 'Desactivar este correo',
        'disable_body' => 'Dejará de enviarse a nadie de tu organización hasta que lo actives de nuevo.',
        'enable_heading' => 'Activar este correo',
        'enable_body' => 'Volverá a enviarse cuando ocurra el hecho que lo dispara.',
        'restore_heading' => 'Restaurar el texto predeterminado',
        'restore_body' => 'Se borrará tu personalización de este correo en todos los idiomas y se usará el texto del sistema.',
        'restore_locale_body' => 'Se borrará tu personalización de este correo en el idioma que tienes abierto y se usará el texto del sistema.',
    ],
    'notifications' => [
        'disabled' => 'Correo desactivado.',
        'enabled' => 'Correo activado.',
        'critical' => 'Este correo es crítico y no se puede desactivar.',
        'saved' => 'Plantilla guardada.',
        'restored' => 'Se restauró el texto predeterminado.',
        'nothing_to_restore' => 'No había nada que restaurar.',
        'invalid' => 'Revisa los campos marcados.',
    ],
    'edit' => [
        'title' => 'Correo: :name',
        'read_only' => 'Tu rol solo permite ver esta plantilla. Para cambiarla pide a un administrador de la organización.',
        'sections' => [
            'content' => 'Contenido',
            'sender' => 'Remitente',
        ],
        'fields' => [
            'locale' => 'Idioma',
            'subject' => 'Asunto',
            'body' => 'Mensaje',
            'from_name' => 'Nombre del remitente',
            'reply_to' => 'Responder a',
        ],
        'help' => [
            'subject' => 'Una sola línea. Puedes usar variables como {{ author_name }}.',
            'body' => 'Texto con formato Markdown (negritas, listas y enlaces). No se admite HTML. Usa solo las variables permitidas.',
            'from_name' => 'Nombre que ve quien recibe el correo. La dirección de envío no cambia.',
            'reply_to' => 'Dirección a la que llegarán las respuestas. Déjala vacía para usar la predeterminada.',
            'variables' => 'Variables permitidas: :variables',
        ],
        'source' => [
            'organization' => 'Usando la plantilla personalizada de tu organización.',
            'system' => 'Usando el texto predeterminado del sistema. Al guardar, se creará tu versión.',
        ],
        'preview' => [
            'heading' => 'Vista previa con datos de ejemplo',
            'subject' => 'Asunto',
            'note' => 'Es solo un ejemplo: no se envía ningún correo.',
        ],
    ],
];
