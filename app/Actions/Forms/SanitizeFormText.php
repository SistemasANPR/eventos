<?php

declare(strict_types=1);

namespace App\Actions\Forms;

/**
 * Deja un texto de formulario (etiqueta, ayuda, opción, nombre) como texto plano
 * de una sola línea (plan 005, CA-005-3): quita las etiquetas, el contenido de
 * `<script>` y `<style>` y los saltos de línea. Lo que se guarda ya está limpio y
 * aun así se muestra siempre escapado.
 */
final class SanitizeFormText
{
    public function handle(string $text): string
    {
        $withoutCode = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $text) ?? '';
        $plain = strip_tags($withoutCode);

        return trim(preg_replace('/\s+/u', ' ', $plain) ?? '');
    }
}
