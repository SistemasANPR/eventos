<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Speaker;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Plan 021, F3b, CA-021-18: las imágenes del programa (foto de ponente, icono e imagen de actividad)
 * viven en un disco privado y solo se sirven por estas rutas públicas, si el registro está publicado,
 * no está borrado y su evento está publicado.
 */

beforeEach(function (): void {
    Storage::fake(MediaDisk::name());
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** @return array{0: Organization, 1: Event} organización y evento publicado */
function mediaEvent(EventStatus $status = EventStatus::Published): array
{
    $organization = Organization::factory()->create();
    $event = Event::factory()->create(['organization_id' => $organization->id, 'status' => $status]);

    return [$organization, $event];
}

function storedImage(Event $event, string $name): string
{
    $path = "organizations/{$event->organization_id}/events/{$event->id}/media/image/{$name}.png";
    MediaDisk::get()->put($path, UploadedFile::fake()->image("{$name}.png", 10, 10)->getContent(), 'private');

    return $path;
}

/** @param  array<string, mixed>  $attributes */
function mediaSpeaker(Event $event, array $attributes = []): Speaker
{
    $attributes += ['photo_path' => storedImage($event, 'foto')];

    return CurrentOrganization::run($event->organization, fn (): Speaker => Speaker::factory()->create(['event_id' => $event->id, ...$attributes]));
}

/** @param  array<string, mixed>  $attributes */
function mediaActivity(Event $event, array $attributes = []): EventActivity
{
    $attributes += ['image_path' => storedImage($event, 'imagen'), 'icon_path' => storedImage($event, 'icono')];

    return CurrentOrganization::run($event->organization, fn (): EventActivity => EventActivity::factory()->create(['event_id' => $event->id, ...$attributes]));
}

function speakerPhotoUrl(Organization $organization, Event $event, Speaker|string $speaker): string
{
    return route('public.speakers.photo', [
        'organization' => $organization->slug,
        'event' => $event->slug,
        'speaker' => $speaker instanceof Speaker ? $speaker->id : $speaker,
    ]);
}

function activityMediaUrl(Organization $organization, Event $event, EventActivity|string $activity, string $type): string
{
    return route('public.activities.media', [
        'organization' => $organization->slug,
        'event' => $event->slug,
        'activity' => $activity instanceof EventActivity ? $activity->id : $activity,
        'type' => $type,
    ]);
}

it('CA-021-18: serves the photo of a published speaker to anyone, without session, with safe headers', function (): void {
    [$organization, $event] = mediaEvent();
    $speaker = mediaSpeaker($event);

    $response = $this->get(speakerPhotoUrl($organization, $event, $speaker));

    $response->assertOk();
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Type'))->toStartWith('image/')
        ->and($response->headers->get('Cache-Control'))->toContain('public');
});

it('CA-021-18: serves the icon and the image of a published activity', function (string $type): void {
    [$organization, $event] = mediaEvent();
    $activity = mediaActivity($event);

    $this->get(activityMediaUrl($organization, $event, $activity, $type))->assertOk();
})->with(['icon', 'image']);

it('CA-021-18: an unpublished or deleted speaker or activity serves nothing', function (): void {
    [$organization, $event] = mediaEvent();
    $hiddenSpeaker = mediaSpeaker($event, ['is_published' => false]);
    $deletedSpeaker = mediaSpeaker($event);
    $hiddenActivity = mediaActivity($event, ['is_published' => false]);
    $deletedActivity = mediaActivity($event);
    CurrentOrganization::run($organization, function () use ($deletedSpeaker, $deletedActivity): void {
        $deletedSpeaker->delete();
        $deletedActivity->delete();
    });

    $this->get(speakerPhotoUrl($organization, $event, $hiddenSpeaker))->assertNotFound();
    $this->get(speakerPhotoUrl($organization, $event, $deletedSpeaker->id))->assertNotFound();
    $this->get(activityMediaUrl($organization, $event, $hiddenActivity, 'image'))->assertNotFound();
    $this->get(activityMediaUrl($organization, $event, $deletedActivity->id, 'icon'))->assertNotFound();
});

it('CA-021-18: a speaker hidden by a reversal, and one without a photo, serve nothing', function (): void {
    [$organization, $event] = mediaEvent();
    $reversed = mediaSpeaker($event, ['is_published' => false, 'hidden_by_reversal_at' => now()]);
    $withoutPhoto = mediaSpeaker($event, ['photo_path' => null]);
    $withoutIcon = mediaActivity($event, ['icon_path' => null]);

    $this->get(speakerPhotoUrl($organization, $event, $reversed))->assertNotFound();
    $this->get(speakerPhotoUrl($organization, $event, $withoutPhoto))->assertNotFound();
    $this->get(activityMediaUrl($organization, $event, $withoutIcon, 'icon'))->assertNotFound();
});

it('CA-021-18: nothing is served while the event is a draft or archived', function (EventStatus $status): void {
    [$organization, $event] = mediaEvent($status);
    $speaker = mediaSpeaker($event);
    $activity = mediaActivity($event);

    $this->get(speakerPhotoUrl($organization, $event, $speaker))->assertNotFound();
    $this->get(activityMediaUrl($organization, $event, $activity, 'image'))->assertNotFound();
})->with([EventStatus::Draft, EventStatus::Archived]);

it('CA-021-17 CA-021-18: another organization or another event never reaches the image', function (): void {
    [$organization, $event] = mediaEvent();
    [$otherOrganization, $otherEvent] = mediaEvent();
    $sibling = Event::factory()->create(['organization_id' => $organization->id, 'status' => EventStatus::Published]);
    $speaker = mediaSpeaker($event);
    $activity = mediaActivity($event);

    // Con la organización ajena en la URL, o con otro evento de la misma organización.
    $this->get(speakerPhotoUrl($otherOrganization, $otherEvent, $speaker))->assertNotFound();
    $this->get(speakerPhotoUrl($organization, $sibling, $speaker))->assertNotFound();
    $this->get(activityMediaUrl($otherOrganization, $otherEvent, $activity, 'image'))->assertNotFound();
    $this->get(activityMediaUrl($organization, $sibling, $activity, 'image'))->assertNotFound();
});

it('CA-021-18: a malformed identifier or an unknown image type answers 404', function (): void {
    [$organization, $event] = mediaEvent();

    $this->get(speakerPhotoUrl($organization, $event, 'no-es-un-uuid'))->assertNotFound();
    $this->get(activityMediaUrl($organization, $event, 'no-es-un-uuid', 'image'))->assertNotFound();
    $this->get("/{$organization->slug}/events/{$event->slug}/activities/".fake()->uuid().'/secret')->assertNotFound();
});

it('CA-021-18: the media disk is private and the response comes from the configured disk', function (): void {
    [$organization, $event] = mediaEvent();
    $speaker = mediaSpeaker($event);

    expect(Storage::disk(MediaDisk::name())->getVisibility((string) $speaker->photo_path))->toBe('private');

    // Otro disco configurado (MEDIA_DISK): la ruta sigue sirviendo sin cambiar el código.
    config(['filesystems.media_disk' => 'other_media']);
    config(['filesystems.disks.other_media' => ['driver' => 'local', 'root' => storage_path('framework/testing/other_media')]]);
    Storage::fake('other_media');
    Storage::disk('other_media')->put((string) $speaker->photo_path, UploadedFile::fake()->image('b.png', 8, 8)->getContent());

    $this->get(speakerPhotoUrl($organization, $event, $speaker))->assertOk();
});
