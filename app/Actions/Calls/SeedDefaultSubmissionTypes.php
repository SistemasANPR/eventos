<?php

declare(strict_types=1);

namespace App\Actions\Calls;

use App\Models\Event;
use App\Models\SubmissionType;
use App\Support\Calls\DefaultSubmissionTypes;

/**
 * Da a un evento sus tipos de propuesta iniciales (plan 004, DA-7, CA-004-29).
 * Lo ejecuta la creación del evento, que ya tiene el contexto de organización y
 * la autorización resueltos; no es un caso de uso de cara al usuario. No hace
 * nada si el evento ya tiene tipos, así que repetirlo no duplica.
 */
final class SeedDefaultSubmissionTypes
{
    public function handle(Event $event): void
    {
        if (SubmissionType::query()->where('event_id', $event->id)->exists()) {
            return;
        }

        foreach (DefaultSubmissionTypes::NAMES as $index => $name) {
            $type = new SubmissionType;
            $type->event_id = $event->id;
            $type->forceFill(['name' => $name, 'position' => $index + 1])->save();
        }
    }
}
