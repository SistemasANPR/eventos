<?php

declare(strict_types=1);

/*
 * Ayudas de las pruebas de la capa pública del programa (plan 029): evento publicado, actividades,
 * ponentes y categorías con sus vínculos. Se cargan con `require_once` para poder correr cualquier
 * prueba por separado.
 */

use App\Actions\Program\ListPublicProgramDay;
use App\Enums\EventStatus;
use App\Models\ActivityCategory;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\SpeakerCategory;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

function ppEvent(?Organization $organization = null): Event
{
    $organization ??= Organization::factory()->create();

    return Event::factory()->create(['organization_id' => $organization->id, 'status' => EventStatus::Published]);
}

/** @param  array<string, mixed>  $attributes */
function ppActivity(Event $event, array $attributes = []): EventActivity
{
    return CurrentOrganization::run($event->organization, fn (): EventActivity => EventActivity::factory()->create(['event_id' => $event->id, ...$attributes]));
}

/** @param  array<string, mixed>  $attributes */
function ppSpeaker(Event $event, array $attributes = []): Speaker
{
    return CurrentOrganization::run($event->organization, fn (): Speaker => Speaker::factory()->create(['event_id' => $event->id, ...$attributes]));
}

function ppCategory(Event $event, string $name, int $position = 1): ActivityCategory
{
    return CurrentOrganization::run($event->organization, fn (): ActivityCategory => ActivityCategory::factory()->create(['event_id' => $event->id, 'name' => $name, 'position' => $position]));
}

function ppSpeakerCategory(Event $event, string $name, int $position = 1): SpeakerCategory
{
    return CurrentOrganization::run($event->organization, fn (): SpeakerCategory => SpeakerCategory::factory()->create(['event_id' => $event->id, 'name' => $name, 'position' => $position]));
}

/** @param  list<Speaker>  $speakers en el orden en que se asignan */
function ppAssign(EventActivity $activity, array $speakers): void
{
    foreach ($speakers as $position => $speaker) {
        DB::table('activity_speaker')->insert([
            'activity_id' => $activity->id,
            'speaker_id' => $speaker->id,
            'organization_id' => $activity->organization_id,
            'event_id' => $activity->event_id,
            'position' => $position,
        ]);
    }
}

function ppTag(EventActivity $activity, ActivityCategory $category): void
{
    DB::table('activity_activity_category')->insert([
        'activity_id' => $activity->id,
        'activity_category_id' => $category->id,
        'organization_id' => $activity->organization_id,
        'event_id' => $activity->event_id,
    ]);
}

/**
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function ppIn(Event $event, Closure $callback): mixed
{
    return CurrentOrganization::run($event->organization, $callback);
}

/** @return list<string> */
function ppDayTitles(Event $event, ?string $day = null): array
{
    $result = ppIn($event, fn () => app(ListPublicProgramDay::class)->handle($event, $day));

    return $result->activities->getCollection()->pluck('title')->all();
}
