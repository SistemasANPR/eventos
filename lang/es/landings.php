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
        'saved' => 'Borrador guardado.',
        'published' => 'Landing actualizada.',
        'discarded' => 'Cambios descartados.',
        'image_uploaded' => 'Imagen subida.',
        'failed' => 'No se pudo guardar la landing.',
        'unavailable' => 'El editor no está disponible en esta compilación.',
    ],
];
