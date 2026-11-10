<?php

declare(strict_types=1);

namespace App\Http\Requests\Calls;

use App\Models\Event;
use App\Models\Track;
use Illuminate\Support\Facades\Gate;

/** Mismas reglas que al crear; editar no cambia el evento ni la posición. */
class UpdateTrackRequest extends StoreTrackRequest
{
    public function authorize(): bool
    {
        $track = $this->route('track');

        return $track instanceof Track && Gate::allows('update', $track);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $event = $this->route('event');

        return $event instanceof Event ? self::rulesFor($event) : [];
    }
}
