<?php

declare(strict_types=1);

/*
 * Plantillas predeterminadas del sistema, en español (plan 008, DA-4). Cuerpo en
 * Markdown; las variables `{{ variable }}` son las de la lista blanca de cada
 * descriptor del catálogo. No se editan desde la interfaz.
 */
return [
    'auth' => [
        'password_reset' => [
            'subject' => 'Restablece tu contraseña',
            'body' => "Hola {{ user_name }},\n\nRecibimos una solicitud para restablecer tu contraseña. Usa este enlace, que caduca en {{ expires_in_minutes }} minutos:\n\n{{ reset_url }}\n\nSi no fuiste tú, ignora este correo.",
        ],
        'new_device_login' => [
            'subject' => 'Inicio de sesión desde un dispositivo nuevo',
            'body' => "Hola {{ user_name }},\n\nIniciaron sesión en tu cuenta el {{ occurred_at }} (UTC) desde {{ device }}.\n\nSi fuiste tú, no tienes que hacer nada. Si no, cambia tu contraseña de inmediato.",
        ],
    ],
    'team' => [
        'set_password' => [
            'subject' => 'Te dieron de alta en {{ organization_name }}',
            'body' => "Hola {{ member_name }},\n\n{{ organization_name }} te dio de alta con el rol {{ role_name }}. Establece tu contraseña con este enlace:\n\n{{ set_password_url }}",
        ],
        'membership_added' => [
            'subject' => 'Ahora formas parte de {{ organization_name }}',
            'body' => "Hola {{ member_name }},\n\n{{ organization_name }} te añadió con el rol {{ role_name }}. Entra con tu cuenta de siempre:\n\n{{ login_url }}",
        ],
    ],
    'submission' => [
        'received' => [
            'subject' => 'Recibimos tu propuesta para {{ event_name }}',
            'body' => "Hola {{ author_name }},\n\nRecibimos tu propuesta «{{ submission_title }}». Tu referencia es {{ submission_reference }}.",
        ],
        'access_link' => [
            'subject' => 'Tu enlace de acceso a {{ event_name }}',
            'body' => "Hola {{ author_name }},\n\nUsa este enlace de un solo uso para consultar tu propuesta. Caduca en {{ expires_in_minutes }} minutos:\n\n{{ access_url }}",
        ],
        'new_for_organizers' => [
            'subject' => 'Nueva propuesta en {{ event_name }}',
            'body' => "{{ author_name }} envió la propuesta «{{ submission_title }}».\n\nRevísala en el panel: {{ panel_url }}",
        ],
        'updated' => [
            'subject' => 'Propuesta editada en {{ event_name }}',
            'body' => "{{ author_name }} editó la propuesta «{{ submission_title }}».\n\nRevísala en el panel: {{ panel_url }}",
        ],
        'withdrawn' => [
            'subject' => 'Propuesta retirada en {{ event_name }}',
            'body' => "{{ author_name }} retiró la propuesta «{{ submission_title }}».\n\nConsúltala en el panel: {{ panel_url }}",
        ],
    ],
];
