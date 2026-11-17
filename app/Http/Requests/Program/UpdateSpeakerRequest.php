<?php

declare(strict_types=1);

namespace App\Http\Requests\Program;

use App\Models\Event;
use App\Models\Speaker;
use Illuminate\Support\Facades\Gate;

/** Mismas reglas que al crear; el correo no choca con la propia ficha y se puede quitar la fotografía. */
class UpdateSpeakerRequest extends StoreSpeakerRequest
{
    public function authorize(): bool
    {
        $speaker = $this->route('speaker');

        return $speaker instanceof Speaker && Gate::allows('update', $speaker);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $event = $this->route('event');
        $speaker = $this->route('speaker');

        if (! $event instanceof Event || ! $speaker instanceof Speaker) {
            return [];
        }

        return self::rulesForUpdate($event, $speaker->id);
    }

    /** @return array<string, mixed> */
    public static function rulesForUpdate(Event $event, string $speakerId): array
    {
        return [...self::rulesFor($event, $speakerId), 'remove_photo' => ['sometimes', 'boolean']];
    }
}
