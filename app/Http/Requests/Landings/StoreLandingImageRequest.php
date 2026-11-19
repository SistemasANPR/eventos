<?php

declare(strict_types=1);

namespace App\Http\Requests\Landings;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas de la subida de una imagen de la landing (plan 022, DA-7, CA-022-29, 30), reutilizadas por la
 * Action y por el panel. Aquí solo se exige que sea un archivo subido sin errores; el tipo (JPEG, PNG o
 * WebP, sin SVG), el tamaño (5 MB), los píxeles (40 millones) y el contenido sospechoso los decide
 * `StoreProgramImage` por el CONTENIDO real, no por lo que declare el cliente.
 */
class StoreLandingImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && Gate::allows('updateLanding', $event);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::rulesFor();
    }

    /** @return array<string, list<string>> */
    public static function rulesFor(): array
    {
        return ['file' => ['required', 'file']];
    }
}
