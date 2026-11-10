<?php

declare(strict_types=1);

namespace App\Http\Requests\Calls;

use App\Models\Event;
use App\Models\SubmissionType;
use Illuminate\Support\Facades\Gate;

/** Mismas reglas que al crear; renombrar no cambia el evento ni la posición. */
class UpdateSubmissionTypeRequest extends StoreSubmissionTypeRequest
{
    public function authorize(): bool
    {
        $type = $this->route('submission_type');

        return $type instanceof SubmissionType && Gate::allows('update', $type);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $event = $this->route('event');

        return $event instanceof Event ? self::rulesFor($event) : [];
    }
}
