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
            'body' => "Hola {{ user_name }},\n\nRecibimos una solicitud para restablecer tu contraseña. Usa este enlace, que caduca en {{ expires_in_minutes }} minutos:\n\n[{{ reset_url }}]({{ reset_url }})\n\nSi no fuiste tú, ignora este correo.",
        ],
        'new_device_login' => [
            'subject' => 'Inicio de sesión desde un dispositivo nuevo',
            'body' => "Hola {{ user_name }},\n\nIniciaron sesión en tu cuenta el {{ occurred_at }} (UTC) desde {{ device }}.\n\nSi fuiste tú, no tienes que hacer nada. Si no, cambia tu contraseña de inmediato.",
        ],
        'two_factor_enabled' => [
            'subject' => 'Activaste la verificación en dos pasos',
            'body' => "Hola {{ user_name }},\n\nSe activó la verificación en dos pasos en tu cuenta el {{ occurred_at }} (UTC).\n\nSi no fuiste tú, avisa de inmediato a un administrador de tu organización.",
        ],
        'two_factor_reset' => [
            'subject' => 'Reiniciaron tu verificación en dos pasos',
            'body' => "Hola {{ user_name }},\n\nUn administrador reinició la verificación en dos pasos de tu cuenta el {{ occurred_at }} (UTC). La próxima vez que inicies sesión deberás configurarla de nuevo.\n\nSi no lo esperabas, avisa de inmediato a un administrador de tu organización y cambia tu contraseña.",
        ],
    ],
    'team' => [
        'set_password' => [
            'subject' => 'Te dieron de alta en {{ organization_name }}',
            'body' => "Hola {{ member_name }},\n\n{{ organization_name }} te dio de alta con el rol {{ role_name }}. Establece tu contraseña con este enlace:\n\n[{{ set_password_url }}]({{ set_password_url }})",
        ],
        'membership_added' => [
            'subject' => 'Ahora formas parte de {{ organization_name }}',
            'body' => "Hola {{ member_name }},\n\n{{ organization_name }} te añadió con el rol {{ role_name }}. Entra con tu cuenta de siempre:\n\n[{{ login_url }}]({{ login_url }})",
        ],
    ],
    'submission' => [
        'received' => [
            'subject' => 'Recibimos tu propuesta para {{ event_name }}',
            'body' => "Hola {{ author_name }},\n\n¡Gracias por participar! Confirmamos que tu registro quedó recibido.\n\n**Evento:** {{ event_name }}\n\n**Convocatoria:** {{ call_title }}\n\n**Tipo de propuesta:** {{ submission_type }}\n\n**Tema:** {{ track_name }}\n\n**Tu propuesta:** «{{ submission_title }}»\n\n**Tu referencia:** {{ submission_reference }}\n\nGuarda tu referencia: te servirá si necesitas escribirnos sobre tu propuesta. Nuestro equipo la revisará y te avisaremos de los siguientes pasos.",
        ],
        'access_link' => [
            'subject' => 'Tu enlace de acceso a {{ event_name }}',
            'body' => "Hola {{ author_name }},\n\nUsa este enlace de un solo uso para consultar tu propuesta. Caduca en {{ expires_in_minutes }} minutos:\n\n[{{ access_url }}]({{ access_url }})",
        ],
        'new_for_organizers' => [
            'subject' => 'Nueva propuesta en {{ event_name }}',
            'body' => "{{ author_name }} envió la propuesta «{{ submission_title }}».\n\nRevísala en el panel: [{{ panel_url }}]({{ panel_url }})",
        ],
        'updated' => [
            'subject' => 'Propuesta editada en {{ event_name }}',
            'body' => "{{ author_name }} editó la propuesta «{{ submission_title }}».\n\nRevísala en el panel: [{{ panel_url }}]({{ panel_url }})",
        ],
        'withdrawn' => [
            'subject' => 'Propuesta retirada en {{ event_name }}',
            'body' => "{{ author_name }} retiró la propuesta «{{ submission_title }}».\n\nConsúltala en el panel: [{{ panel_url }}]({{ panel_url }})",
        ],
        'withdrawn_author' => [
            'subject' => 'Retiraste tu propuesta en {{ event_name }}',
            'body' => 'Hola {{ author_name }},

Confirmamos que retiraste tu propuesta «{{ submission_title }}». Ya no participa en la convocatoria.

Si necesitas volver a consultarla, pide un nuevo enlace de acceso: [{{ request_link_url }}]({{ request_link_url }})',
        ],
    ],
    'reviewer' => [
        'assignment' => [
            'subject' => 'Tienes propuestas por evaluar en {{ event_name }}',
            'body' => "Hola {{ reviewer_name }},\n\nTe asignaron {{ assigned_count }} propuesta(s) para evaluar en {{ event_name }}.\n\nEntra a tu tablero para verlas: [{{ dashboard_url }}]({{ dashboard_url }})",
        ],
        'invitation' => [
            'subject' => '{{ organization_name }} te invitó a evaluar propuestas',
            'body' => "Hola {{ reviewer_name }},\n\n{{ organization_name }} te invitó a evaluar propuestas de {{ event_name }}.\n\nUsa este enlace para entrar a tu panel. Si aún no tienes contraseña, ahí podrás establecerla (el enlace caduca en {{ expires_in_minutes }} minutos):\n\n[{{ access_url }}]({{ access_url }})\n\nSi no esperabas esta invitación, ignora este correo.",
        ],
    ],
];
