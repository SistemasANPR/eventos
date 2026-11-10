<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Event;
use App\Models\Organization;
use App\Models\SubmissionType;
use App\Support\Tenancy\CurrentOrganization;

/** Ayudas de prueba: tipos de propuesta de un evento, sin pasar por la creación del evento. */
final class SubmissionTypes
{
    /** El tipo «Sesión» del evento; lo crea la primera vez que se pide. */
    public static function for(Event $event, string $name = 'Sesión'): SubmissionType
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($event, $name): SubmissionType {
            return SubmissionType::query()
                ->where('event_id', $event->id)
                ->where('name->es', $name)
                ->first() ?? SubmissionType::factory()->create([
                    'organization_id' => $event->organization_id,
                    'event_id' => $event->id,
                    'name' => ['es' => $name],
                    'position' => (int) SubmissionType::query()->where('event_id', $event->id)->max('position') + 1,
                ]);
        });
    }
}
