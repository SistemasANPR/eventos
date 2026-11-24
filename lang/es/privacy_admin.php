<?php

declare(strict_types=1);

/*
 * Textos de las pantallas de privacidad del panel de la organización (plan 014, F4 y F5): datos de una persona
 * (exportar y anonimizar) y plazos de retención.
 */
return [
    'navigation' => [
        'personal_data' => 'Datos de una persona',
        'retention' => 'Retención de datos',
    ],

    'reasons' => [
        'data_subject_request' => 'Solicitud de la persona titular',
        'consent_withdrawn' => 'Retiro del consentimiento',
        'legal_order' => 'Requerimiento de una autoridad',
        'other' => 'Otro motivo',
    ],

    'personal_data' => [
        'title' => 'Datos de una persona',
        'subtitle' => 'Exporta todo lo que esta organización guarda de una persona, o anonimízalo. Se busca por su correo en propuestas, ponentes, contactos, evaluaciones, correos enviados y acceso al panel.',
        'fields' => [
            'email' => 'Correo de la persona',
            'email_help' => 'El correo con el que figura en esta organización.',
            'reason' => 'Motivo',
            'confirmation' => 'Escribe de nuevo el correo para confirmar',
            'confirmation_help' => 'La anonimización no se puede deshacer.',
        ],
        'actions' => [
            'export' => 'Exportar datos',
            'anonymize' => 'Anonimizar',
            'anonymize_heading' => 'Anonimizar a esta persona',
            'anonymize_body' => 'Se sustituyen su nombre, correo y demás datos personales por valores no identificables y se borran los archivos que subió. Se conservan los totales, los estados y las calificaciones. No se puede deshacer.',
            'anonymize_submit' => 'Anonimizar definitivamente',
            'download' => 'Descargar archivo',
        ],
        'notifications' => [
            'exported' => 'Exportación lista',
            'exported_body' => 'El enlace de descarga sirve solo para ti y caduca en :minutes minutos; el archivo se borra a las 24 horas.',
            'not_found' => 'No hay datos de esa persona en esta organización.',
            'anonymized' => 'Persona anonimizada',
            'anonymized_body' => 'Registros modificados: :total. Archivos borrados: :files.',
            'already_anonymized' => 'Esa persona ya estaba anonimizada.',
            'account_kept' => 'Su cuenta de acceso se conserva porque pertenece también a otra organización; aquí perdió su acceso.',
            'own_account' => 'No puedes anonimizar tu propia cuenta.',
            'platform_account' => 'No se puede anonimizar a un usuario de plataforma desde una organización.',
            'last_admin' => 'Es el último administrador activo de la organización: designa a otro antes de anonimizarlo.',
            'files_failed' => 'No se pudo borrar un archivo del almacenamiento. No se cambió ningún dato; vuelve a intentarlo.',
            'salt_missing' => 'La plataforma no tiene configurada la sal de las anonimizaciones. Avisa a quien la administra.',
        ],
    ],

    'retention' => [
        'title' => 'Retención de datos',
        'subtitle' => 'Define cuántos días se conserva cada categoría de datos. Pasado el plazo, un trabajo diario anonimiza o elimina lo vencido. Una categoría sin plazo no se toca.',
        'fields' => [
            'days' => 'Plazo en días',
            'days_help' => 'Déjalo vacío para no aplicar ningún plazo a esta categoría.',
        ],
        'categories' => [
            'unaccepted_submissions' => 'Propuestas rechazadas o retiradas',
            'reviews' => 'Comentarios de las evaluaciones',
            'mail_dispatches' => 'Envíos de correo',
            'audit_log' => 'Auditoría de la organización',
            'backups' => 'Respaldos (días máximos de conservación)',
        ],
        'descriptions' => [
            'unaccepted_submissions' => 'Se anonimiza a sus autores y se borran sus archivos; se conserva el contenido de la propuesta.',
            'reviews' => 'Se sustituye el texto de los comentarios; se conservan las calificaciones.',
            'mail_dispatches' => 'Se rompe el vínculo del envío con su destinatario; se conservan el tipo, el estado y la fecha.',
            'audit_log' => 'Se eliminan los registros de la auditoría de esta organización más antiguos que el plazo.',
            'backups' => 'Solo declarativo: lo que la plataforma debe configurar en el proveedor de respaldos. Ningún trabajo borra respaldos.',
        ],
        'actions' => [
            'save' => 'Guardar plazos',
        ],
        'notifications' => [
            'saved' => 'Plazos guardados',
        ],
        'validation' => [
            'days_range' => 'El plazo debe estar entre :min y :max días.',
        ],
    ],
];
