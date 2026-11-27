<?php

declare(strict_types=1);

/*
 * Textos de la landing del evento (plan 022): errores de validación de los bloques, mensajes del
 * editor y etiquetas del panel. Las etiquetas fijas de la página pública viven en `public.php`.
 */
return [
    'errors' => [
        'invalid' => 'El valor no es válido.',
        'required' => 'Este campo es obligatorio.',
        'unknown_field' => 'Este campo no lo admite el bloque.',
        'locale_not_offered' => 'El evento no ofrece este idioma.',
        'too_long' => 'No puede superar :max caracteres.',
        'too_many' => 'No puede tener más de :max elementos.',
        'too_many_blocks' => 'La landing no puede tener más de :max bloques.',
        'too_many_images' => 'La landing no puede usar más de :max imágenes.',
        'too_large' => 'El contenido de la landing no puede superar :max KB.',
        'block_type' => 'El tipo de bloque no es válido.',
        'url_https' => 'El enlace debe ser una dirección https externa.',
        'image_not_found' => 'La imagen no existe en esta landing.',
        'image_limit' => 'La landing ya tiene :max imágenes; quita alguna antes de subir otra.',
        'image_in_use' => 'La imagen está en la versión publicada; quítala de los bloques y actualiza primero.',
        'nothing_to_discard' => 'La landing todavía no tiene versión publicada: no hay a qué volver.',
        'version_conflict' => 'La landing cambió desde que la abriste. Recarga el editor para ver la versión más reciente.',
    ],
    'editor' => [
        'navigation' => 'Diseñar landing',
        'title' => 'Landing de :name',
        'call_title' => 'Landing de :call · :name',
        'saved' => 'Borrador guardado.',
        'published' => 'Landing actualizada.',
        'discarded' => 'Cambios descartados.',
        'image_uploaded' => 'Imagen subida.',
        'failed' => 'No se pudo guardar la landing.',
        'unavailable' => 'El editor no está disponible en esta compilación.',
    ],
    // Textos de relleno de la plantilla de la landing de una convocatoria (plan 033): genéricos, sin datos inventados.
    'call_template' => [
        'axes_title' => 'Ejes temáticos',
        'axes_intro' => 'Tu propuesta debe enmarcarse en uno de los ejes de esta edición. En el formulario eliges el que mejor la describa.',
        'requirements_title' => 'Prepara tu propuesta',
        'requirements_intro' => 'Ten listos estos datos antes de abrir el formulario; así lo completas en una sola sesión.',
        'requirement_one' => 'Reúne la información que pide el formulario.',
        'requirement_two' => 'Completa el formulario en línea antes de la fecha de cierre.',
        'requirements_button' => 'Abrir el formulario',
        'steps_title' => 'Cómo funciona',
        'step_prepare_title' => 'Prepara',
        'step_prepare_text' => 'Reúne la información que pide el formulario.',
        'step_send_title' => 'Envía',
        'step_send_text' => 'Completa el formulario en línea antes de la fecha de cierre.',
        'step_review_title' => 'Evaluación',
        'step_review_text' => 'Las propuestas recibidas serán revisadas por el comité evaluador.',
        'step_results_title' => 'Resultados',
        'step_results_text' => 'Te avisaremos el resultado por correo.',
        'step_present_title' => 'Presenta',
        'step_present_text' => 'Las propuestas seleccionadas forman parte del programa del evento.',
        'faq_title' => 'Preguntas frecuentes',
        'faq_one_question' => '¿Dónde envío mi propuesta?',
        'faq_one_answer' => 'Con el botón «Enviar propuesta», mientras la convocatoria esté abierta.',
        'faq_two_question' => '¿Cuándo sabré el resultado?',
        'faq_two_answer' => 'Te avisaremos por correo cuando termine la evaluación.',
        'promo_button' => 'Ver convocatoria',
    ],
    // Líneas que la landing de una convocatoria arma sola con los datos reales (plan 033).
    'call_live' => [
        'team' => 'Participa de forma **individual o en equipo**, con un máximo de **:max personas** en total.',
        'individual' => 'La propuesta es **individual**.',
        'minimum' => 'Cada propuesta necesita al menos **:min personas**.',
        'one_axis' => 'Cada propuesta se registra en **un solo eje temático**: elige el que corresponda a su tema central.',
        'form_title' => 'Lo que te pide el formulario',
        'form_required' => '* obligatorio',
        'form_proposal' => 'Información de la propuesta',
        'form_participant' => 'Información de cada participante',
        'promo_fallback' => 'También puedes participar en otra convocatoria de este evento.',
    ],
];
