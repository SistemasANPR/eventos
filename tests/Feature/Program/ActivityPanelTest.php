<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ManageEventActivities;
use App\Models\ActivityCategory;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\Submission;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 021, F3: «Actividades» como sección del evento. Cada prueba usa un solo rol: la caché de
 * permisos de Spatie se comparte dentro del proceso. Estas pruebas corren con APP_ENV=testing.
 */

beforeEach(function (): void {
    Storage::fake(MediaDisk::name());
    app()->setLocale('es');
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return Testable<ManageEventActivities> */
function activitiesPage(Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test(ManageEventActivities::class, ['record' => $event->id]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function panelActivity(Event $event, array $attributes = [], string $state = 'scheduled'): EventActivity
{
    return CurrentOrganization::run(
        $event->organization,
        fn (): EventActivity => EventActivity::factory()->{$state}()->create(['event_id' => $event->id, ...$attributes]),
    );
}

function activityUrl(Organization $organization, Event $event): string
{
    return EventResource::getUrl('activities', ['record' => $event], panel: 'admin', tenant: $organization);
}

it('CA-021-4: the empty list shows its empty state and the admin sees the create action', function (): void {
    [$organization, $event, $admin] = formScenario();

    activitiesPage($organization, $admin, $event)
        ->assertSee(__('program.activities.empty_heading'))
        ->assertActionVisible('createActivity');
});

it('CA-021-4: the admin creates an activity with the required fields and it is listed as published', function (): void {
    [$organization, $event, $admin] = formScenario();

    $component = activitiesPage($organization, $admin, $event)
        ->callAction('createActivity', [
            'title' => 'Conferencia magistral',
            'starts_on' => '2027-03-10',
            'ends_on' => '2027-03-10',
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'is_published' => true,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified(__('program.activities.messages.saved'));

    $activity = CurrentOrganization::run($organization, fn () => EventActivity::query()->where('event_id', $event->id)->firstOrFail());

    expect($activity->title)->toBe('Conferencia magistral')->and($activity->is_published)->toBeTrue();

    $component->assertCanSeeTableRecords([$activity]);
});

it('CA-021-4: creating with a missing required field or an end before the start shows the error next to the field', function (array $data, string $field): void {
    [$organization, $event, $admin] = formScenario();

    activitiesPage($organization, $admin, $event)
        ->callAction('createActivity', array_replace([
            'title' => 'Taller',
            'starts_on' => '2027-03-10',
            'ends_on' => '2027-03-10',
            'starts_at' => '09:00',
            'ends_at' => '10:00',
        ], $data))
        ->assertHasActionErrors([$field]);

    expect(CurrentOrganization::run($organization, fn () => EventActivity::query()->count()))->toBe(0);
})->with([
    'no title' => [['title' => null], 'title'],
    'no start date' => [['starts_on' => null], 'starts_on'],
    'no end time' => [['ends_at' => null], 'ends_at'],
    'end before the start' => [['starts_at' => '12:00', 'ends_at' => '11:00'], 'ends_at'],
]);

it('CA-021-4: uploading an icon and an image stores them in the media disk', function (): void {
    [$organization, $event, $admin] = formScenario();

    activitiesPage($organization, $admin, $event)
        ->callAction('createActivity', [
            'title' => 'Con imágenes',
            'starts_on' => '2027-03-10',
            'ends_on' => '2027-03-10',
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'icon' => UploadedFile::fake()->image('icono.png', 32, 32),
            'image' => UploadedFile::fake()->image('imagen.jpg', 400, 200),
        ])
        ->assertHasNoActionErrors();

    $activity = CurrentOrganization::run($organization, fn () => EventActivity::query()->firstOrFail());

    expect($activity->icon_path)->not->toBeNull()
        ->and($activity->image_path)->not->toBeNull()
        ->and(MediaDisk::get()->allFiles())->toHaveCount(2);
});

it('CA-021-4: the list shows the local date and time, and an activity without them as unscheduled', function (): void {
    [$organization, $event, $admin] = formScenario();
    $scheduled = panelActivity($event, ['title' => 'Programada']);
    $unscheduled = panelActivity($event, ['title' => 'Sin fecha'], 'unscheduled');

    activitiesPage($organization, $admin, $event)
        ->assertCanSeeTableRecords([$scheduled, $unscheduled])
        ->assertTableColumnFormattedStateSet('starts_on', __('program.activities.unscheduled'), $unscheduled)
        ->assertTableColumnFormattedStateSet('starts_at', '—', $unscheduled)
        ->assertSee($event->timezone)
        ->assertSeeText('10:00');
});

it('CA-021-4: editing an unscheduled activity requires the four schedule fields and programs it when saved', function (): void {
    [$organization, $event, $admin] = formScenario();
    $activity = panelActivity($event, ['title' => 'Sin fecha'], 'unscheduled');

    activitiesPage($organization, $admin, $event)
        ->callAction(TestAction::make('editActivity')->table($activity), [
            'title' => 'Sin fecha', 'starts_on' => '2027-03-11', 'ends_on' => '2027-03-11', 'starts_at' => null, 'ends_at' => null,
        ])
        ->assertHasActionErrors(['starts_at', 'ends_at']);

    // Un formulario con errores deja la acción abierta: se vuelve a abrir la página para guardarlo completo.
    activitiesPage($organization, $admin, $event)->callAction(TestAction::make('editActivity')->table($activity), [
        'title' => 'Ya programada', 'starts_on' => '2027-03-11', 'ends_on' => '2027-03-11', 'starts_at' => '15:00', 'ends_at' => '16:30',
    ])->assertHasNoActionErrors();

    $fresh = CurrentOrganization::run($organization, fn () => EventActivity::query()->findOrFail($activity->id));

    expect($fresh->title)->toBe('Ya programada')->and($fresh->isScheduled())->toBeTrue()->and($fresh->starts_at)->toBe('15:00:00');
});

it('CA-021-6: the categories are optional, several are allowed, and the filters narrow the list', function (): void {
    [$organization, $event, $admin] = formScenario();
    $first = CurrentOrganization::run($organization, fn () => ActivityCategory::factory()->create(['event_id' => $event->id, 'name' => 'Taller']));
    $second = CurrentOrganization::run($organization, fn () => ActivityCategory::factory()->create(['event_id' => $event->id, 'name' => 'Panel']));

    $component = activitiesPage($organization, $admin, $event)
        ->callAction('createActivity', [
            'title' => 'Con categorías',
            'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '09:00', 'ends_at' => '10:00',
            'category_ids' => [$first->id, $second->id],
        ])->assertHasNoActionErrors();

    $tagged = CurrentOrganization::run($organization, fn () => EventActivity::query()->firstOrFail());
    $plain = panelActivity($event, ['title' => 'Sin categorías']);

    $component->filterTable('category', $first->id)
        ->assertCanSeeTableRecords([$tagged])
        ->assertCanNotSeeTableRecords([$plain]);
});

it('CA-021-16: the filters narrow by published and by scheduled, and a deleted activity never appears', function (): void {
    [$organization, $event, $admin] = formScenario();
    $published = panelActivity($event, ['title' => 'Publicada']);
    $hidden = panelActivity($event, ['title' => 'Oculta', 'is_published' => false, 'hidden_by_reversal_at' => now()]);
    $unscheduled = panelActivity($event, ['title' => 'Sin fecha'], 'unscheduled');
    $deleted = panelActivity($event, ['title' => 'Borrada']);
    CurrentOrganization::run($organization, fn () => $deleted->delete());

    $component = activitiesPage($organization, $admin, $event)
        ->assertCanSeeTableRecords([$published, $hidden, $unscheduled])
        ->assertCanNotSeeTableRecords([$deleted])
        ->assertSee(__('program.activities.hidden_by_reversal'));

    $component->filterTable('is_published', false)
        ->assertCanSeeTableRecords([$hidden])
        ->assertCanNotSeeTableRecords([$published])
        ->resetTableFilters()
        ->filterTable('schedule', false)
        ->assertCanSeeTableRecords([$unscheduled])
        ->assertCanNotSeeTableRecords([$published, $hidden]);
});

it('CA-021-4: the list can be searched by name', function (): void {
    [$organization, $event, $admin] = formScenario();
    $match = panelActivity($event, ['title' => 'Apertura solemne']);
    $other = panelActivity($event, ['title' => 'Cierre']);

    activitiesPage($organization, $admin, $event)
        ->searchTable('Apertura')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('CA-021-4: the list shows only the activities of its event', function (): void {
    [$organization, $event, $admin] = formScenario();
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    $own = panelActivity($event);
    $foreign = panelActivity($otherEvent);

    activitiesPage($organization, $admin, $event)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('CA-021-4: publishing and unpublishing from the table toggles the flag', function (): void {
    [$organization, $event, $admin] = formScenario();
    $activity = panelActivity($event, ['is_published' => false, 'hidden_by_reversal_at' => now()]);

    $component = activitiesPage($organization, $admin, $event)
        ->callAction(TestAction::make('togglePublication')->table($activity))
        ->assertNotified(__('program.activities.messages.published'));

    $fresh = CurrentOrganization::run($organization, fn () => EventActivity::query()->findOrFail($activity->id));
    expect($fresh->is_published)->toBeTrue()->and($fresh->hidden_by_reversal_at)->toBeNull();

    $component->callAction(TestAction::make('togglePublication')->table($activity))
        ->assertNotified(__('program.activities.messages.unpublished'));
});

it('CA-021-5: an activity created from a submission links to it only for those who can see submissions', function (): void {
    [$organization, $event, $admin] = formScenario();
    $submission = CurrentOrganization::run($organization, fn () => Submission::factory()->create(['event_id' => $event->id, 'title' => 'Propuesta origen']));
    panelActivity($event, ['submission_id' => $submission->id, 'title' => 'De propuesta'], 'unscheduled');

    activitiesPage($organization, $admin, $event)->assertSee('Propuesta origen');
});

it('CA-021-15: the organizer creates, edits and publishes but is not offered the delete action', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    $activity = panelActivity($event);

    activitiesPage($organization, $organizer, $event)
        ->assertActionVisible('createActivity')
        ->assertActionVisible(TestAction::make('editActivity')->table($activity))
        ->assertActionVisible(TestAction::make('togglePublication')->table($activity))
        ->assertActionHidden(TestAction::make('deleteActivity')->table($activity));
});

it('CA-021-16: the admin deletes an activity from the list and it is only logically deleted', function (): void {
    [$organization, $event, $admin] = formScenario();
    $activity = panelActivity($event);

    activitiesPage($organization, $admin, $event)
        ->callAction(TestAction::make('deleteActivity')->table($activity))
        ->assertNotified(__('program.activities.messages.deleted'));

    expect(CurrentOrganization::run($organization, fn () => EventActivity::query()->count()))->toBe(0)
        ->and(CurrentOrganization::run($organization, fn () => EventActivity::query()->withTrashed()->count()))->toBe(1);
});

it('CA-021-15: a reviewer has no access to the activities page, by class or by URL', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);
    Auth::login($reviewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    expect(ManageEventActivities::canAccess(['record' => $event]))->toBeFalse();

    expect($this->actingAs($reviewer)->get(activityUrl($organization, $event))->status())->toBeIn([403, 404]);
});

it('CA-021-15: the admin reaches the page by URL and the event sub-navigation links to it', function (): void {
    [$organization, $event, $admin] = formScenario();
    $url = activityUrl($organization, $event);

    $this->actingAs($admin)->get($url)->assertOk()->assertSee($url, false);
});

it('CA-021-17: the activities page of an event of another organization answers 404', function (): void {
    [$organizationA, , $adminA] = formScenario();
    [, $eventB] = formScenario();

    // Control: la prueba anterior abre la misma ruta con un evento propio y responde 200.
    $this->actingAs($adminA)->get(activityUrl($organizationA, $eventB))->assertNotFound();
});

it('CA-021-17: the list never shows the activities of another organization', function (): void {
    [$organizationA, $eventA, $adminA] = formScenario();
    [, $eventB] = formScenario();
    $own = panelActivity($eventA);
    $foreign = panelActivity($eventB);

    activitiesPage($organizationA, $adminA, $eventA)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([EventActivity::query()->withoutGlobalScopes()->findOrFail($foreign->id)]);
});

it('CA-021-18: the list previews the image of an activity with an embedded thumbnail, without publishing the file', function (): void {
    [$organization, $event, $admin] = formScenario();
    $activity = panelActivity($event);
    $path = "organizations/{$organization->id}/events/{$event->id}/media/image/prueba.png";
    MediaDisk::get()->put($path, UploadedFile::fake()->image('prueba.png', 40, 40)->getContent(), 'private');
    CurrentOrganization::run($organization, fn () => $activity->forceFill(['image_path' => $path])->save());

    activitiesPage($organization, $admin, $event)
        ->assertCanSeeTableRecords([$activity])
        ->assertSeeHtml('data:image/jpeg;base64,');

    expect(MediaDisk::get()->getVisibility($path))->toBe('private');
});

/** @return list<Speaker> ponentes nuevos del evento */
function panelSpeakers(Event $event, int $count = 2): array
{
    return CurrentOrganization::run($event->organization, fn (): array => Speaker::factory()->count($count)->create(['event_id' => $event->id])->all());
}

it('CA-021-5: the form assigns speakers of the event when creating and replaces them when editing, and the list shows them', function (): void {
    [$organization, $event, $admin] = formScenario();
    [$ada, $grace] = panelSpeakers($event);
    $data = ['title' => 'Mesa', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '09:00', 'ends_at' => '10:00'];

    activitiesPage($organization, $admin, $event)
        ->callAction('createActivity', [...$data, 'speaker_ids' => [$ada->id, $grace->id]])
        ->assertHasNoActionErrors();

    $activity = CurrentOrganization::run($organization, fn () => EventActivity::query()->firstOrFail());
    $names = fn (): array => CurrentOrganization::run($organization, fn () => $activity->speakers()->get()->pluck('id')->all());

    expect($names())->toBe([$ada->id, $grace->id]);

    activitiesPage($organization, $admin, $event)
        ->callAction(TestAction::make('editActivity')->table($activity), [...$data, 'speaker_ids' => [$grace->id]])
        ->assertHasNoActionErrors();

    expect($names())->toBe([$grace->id]);

    activitiesPage($organization, $admin, $event)->assertSee($grace->full_name);
});

it('CA-021-5: an activity can be saved with no speakers', function (): void {
    [$organization, $event, $admin] = formScenario();
    [$ada] = panelSpeakers($event, 1);
    $data = ['title' => 'Mesa', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '09:00', 'ends_at' => '10:00'];

    activitiesPage($organization, $admin, $event)->callAction('createActivity', [...$data, 'speaker_ids' => [$ada->id]])->assertHasNoActionErrors();
    $activity = CurrentOrganization::run($organization, fn () => EventActivity::query()->firstOrFail());

    activitiesPage($organization, $admin, $event)
        ->callAction(TestAction::make('editActivity')->table($activity), [...$data, 'speaker_ids' => []])
        ->assertHasNoActionErrors();

    expect(CurrentOrganization::run($organization, fn () => $activity->speakers()->count()))->toBe(0);
});

it('CA-021-5 CA-021-17: a speaker of another event or organization is refused by the form and assigns nothing', function (): void {
    [$organization, $event, $admin] = formScenario();
    [, $otherEvent] = formScenario();
    [$foreign] = panelSpeakers($otherEvent, 1);

    activitiesPage($organization, $admin, $event)
        ->callAction('createActivity', [
            'title' => 'Mesa', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '09:00', 'ends_at' => '10:00',
            'speaker_ids' => [$foreign->id],
        ])
        ->assertHasActionErrors();

    expect(CurrentOrganization::run($organization, fn () => EventActivity::query()->count()))->toBe(0);
});

it('CA-021-2: the description typed in the form is saved sanitized', function (): void {
    [$organization, $event, $admin] = formScenario();

    activitiesPage($organization, $admin, $event)
        ->callAction('createActivity', [
            'title' => 'Mesa', 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-10', 'starts_at' => '09:00', 'ends_at' => '10:00',
            'description' => '<p>Hola</p><script>alert(1)</script>',
        ])
        ->assertHasNoActionErrors();

    $activity = CurrentOrganization::run($organization, fn () => EventActivity::query()->firstOrFail());

    expect($activity->description)->toBe('<p>Hola</p>');
});
