<?php

declare(strict_types=1);

namespace App\Http\Requests\Calls;

use App\Models\CallForSubmissions;
use App\Models\Event;
use Illuminate\Support\Facades\Gate;

/** Mismas reglas que al crear: la URL de la convocatoria no se edita. */
class UpdateCallForSubmissionsRequest extends StoreCallForSubmissionsRequest
{
    public function authorize(): bool
    {
        $call = $this->route('call');

        return $call instanceof CallForSubmissions && Gate::allows('update', $call);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $call = $this->route('call');
        $event = $this->route('event');

        return $call instanceof CallForSubmissions && $event instanceof Event ? self::rulesFor($event, $call->submission_type_id) : [];
    }
}
