<?php

declare(strict_types=1);

namespace App\Http\Requests\Program;

use App\Models\Event;
use App\Models\EventActivity;
use Illuminate\Support\Facades\Gate;

/**
 * Mismas reglas que al crear: editar una actividad «sin programar» exige las cuatro marcas de
 * tiempo al guardarla, y así se programa (plan 021, DA-4). Editar no cambia el evento.
 */
class UpdateActivityRequest extends StoreActivityRequest
{
    public function authorize(): bool
    {
        $activity = $this->route('activity');

        return $activity instanceof EventActivity && Gate::allows('update', $activity);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $event = $this->route('event');

        return $event instanceof Event ? self::rulesFor($event) : [];
    }
}
