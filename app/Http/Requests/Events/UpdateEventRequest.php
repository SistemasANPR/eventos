<?php

declare(strict_types=1);

namespace App\Http\Requests\Events;

use App\Models\Event;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Gate;

/** Mismas reglas que al crear; el `slug` del propio evento no cuenta como repetido. */
class UpdateEventRequest extends StoreEventRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && Gate::allows('update', $event);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $event = $this->route('event');

        /** @var list<string> $locales */
        $locales = (array) $this->input('locales', []);

        return self::rulesFor(CurrentOrganization::id(), $locales, $event instanceof Event ? $event->id : null);
    }
}
