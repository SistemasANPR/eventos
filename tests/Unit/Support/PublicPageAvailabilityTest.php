<?php

declare(strict_types=1);

use App\Enums\CallStatus;
use App\Enums\EventStatus;
use App\Enums\PublicPageUnavailability;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Support\Events\PublicPageAvailability;

function availabilityEvent(EventStatus $status): Event
{
    return (new Event)->forceFill(['status' => $status]);
}

function availabilityCall(CallStatus $status): CallForSubmissions
{
    return (new CallForSubmissions)->forceFill(['status' => $status]);
}

it('CA-030-7: an event alone has a public page only when published, and the reason says why not', function (EventStatus $status, ?PublicPageUnavailability $reason): void {
    $event = availabilityEvent($status);

    expect(PublicPageAvailability::reasonFor($event))->toBe($reason)
        ->and(PublicPageAvailability::isAvailable($event))->toBe($reason === null);
})->with([
    'published' => [EventStatus::Published, null],
    'archived' => [EventStatus::Archived, PublicPageUnavailability::Archived],
    'draft' => [EventStatus::Draft, PublicPageUnavailability::DraftEvent],
]);

it('CA-030-7: every combination of event status and call status gives one answer', function (EventStatus $eventStatus, CallStatus $callStatus, ?PublicPageUnavailability $reason): void {
    $event = availabilityEvent($eventStatus);
    $call = availabilityCall($callStatus);

    expect(PublicPageAvailability::reasonFor($event, $call))->toBe($reason)
        ->and(PublicPageAvailability::isAvailable($event, $call))->toBe($reason === null);
})->with(function (): array {
    $cases = [];

    foreach (EventStatus::cases() as $eventStatus) {
        foreach (CallStatus::cases() as $callStatus) {
            // El estado del evento manda; solo con el evento publicado el borrador de la convocatoria cuenta.
            $reason = match (true) {
                $eventStatus === EventStatus::Archived => PublicPageUnavailability::Archived,
                $eventStatus === EventStatus::Draft => PublicPageUnavailability::DraftEvent,
                $callStatus === CallStatus::Draft => PublicPageUnavailability::DraftCall,
                default => null,
            };

            $cases["{$eventStatus->value} event, {$callStatus->value} call"] = [$eventStatus, $callStatus, $reason];
        }
    }

    return $cases;
});
