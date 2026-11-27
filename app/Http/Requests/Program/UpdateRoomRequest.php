<?php

declare(strict_types=1);

namespace App\Http\Requests\Program;

use App\Models\Event;
use App\Models\Room;
use Illuminate\Support\Facades\Gate;

/** Mismas reglas que al crear; editar no cambia el evento ni la posición, y la sala no choca consigo misma. */
class UpdateRoomRequest extends StoreRoomRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');

        return $room instanceof Room && Gate::allows('update', $room);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $event = $this->route('event');
        $room = $this->route('room');

        return $event instanceof Event && $room instanceof Room ? self::rulesFor($event, $room->id) : [];
    }
}
