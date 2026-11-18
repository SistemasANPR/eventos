<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\SubmissionParticipant;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

function detailUrl(Organization $organization, Event $event, Submission $submission): string
{
    return SubmissionResource::getUrl('view', ['event' => $event, 'record' => $submission], panel: 'admin', tenant: $organization);
}

/**
 * Propuesta con respuestas dinámicas de varios tipos, dos participantes y un archivo adjunto. La
 * instantánea del formulario conserva un campo que ya no existe en la definición vigente (CA-009-9).
 *
 * @param  array<string, mixed>  $overrides
 */
function detailedSubmission(Organization $organization, Event $event, array $overrides = []): Submission
{
    $submission = Submission::factory()->create([
        'organization_id' => $organization->id,
        'event_id' => $event->id,
        'title' => 'Parques inclusivos',
        'form_snapshot' => ['fields' => [
            ['key' => 'f_resumen', 'type' => 'long_text', 'label' => ['es' => 'Resumen de la propuesta'], 'config' => []],
            ['key' => 'f_nivel', 'type' => 'single_choice', 'label' => ['es' => 'Nivel'], 'config' => ['options' => [
                ['value' => 'o_basico', 'label' => ['es' => 'Básico']],
                ['value' => 'o_experto', 'label' => ['es' => 'Experto']],
            ]]],
            ['key' => 'f_retirado', 'type' => 'short_text', 'label' => ['es' => 'Campo retirado después'], 'config' => []],
            ['key' => 'f_presentacion', 'type' => 'file', 'label' => ['es' => 'Presentación'], 'config' => []],
        ]],
        'answers' => [
            'f_resumen' => 'Texto del resumen',
            'f_nivel' => 'o_experto',
            'f_retirado' => 'Respuesta antigua',
            'f_presentacion' => ['archivo-1'],
        ],
        ...$overrides,
    ]);

    SubmissionParticipant::factory()->create([
        'organization_id' => $organization->id,
        'submission_id' => $submission->id,
        'full_name' => 'Ana Autora',
        'email' => 'ana@example.test',
        'job_title' => 'Directora',
        'company' => 'Parques SA',
    ]);
    SubmissionParticipant::factory()->secondary()->create([
        'organization_id' => $organization->id,
        'submission_id' => $submission->id,
        'full_name' => 'Beto Coautor',
        'email' => 'beto@example.test',
    ]);

    return $submission;
}

function attachedFile(Submission $submission): SubmissionFile
{
    return SubmissionFile::factory()->attachedTo($submission->id)->create([
        'organization_id' => $submission->organization_id,
        'call_id' => $submission->call_for_submissions_id,
        'form_field_key' => 'f_presentacion',
        'original_name' => 'mi-presentacion.pdf',
    ]);
}

it('CA-009-6: the detail shows labelled answers, participants and files with the download action for files.download', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $submission = detailedSubmission($organization, $event);
    $file = attachedFile($submission);

    $this->actingAs($user)->get(detailUrl($organization, $event, $submission))
        ->assertOk()
        ->assertSee('Parques inclusivos')
        ->assertSee('Resumen de la propuesta')
        ->assertSee('Texto del resumen')
        ->assertSee('Experto')
        ->assertDontSee('o_experto')
        ->assertSee('Ana Autora')
        ->assertSee('ana@example.test')
        ->assertSee('Beto Coautor')
        ->assertSee('mi-presentacion.pdf')
        ->assertSee(route('submission-files.download', ['organization' => $organization->slug, 'file' => $file->id]), false);
});

it('CA-009-7: the detail of a submission of another organization is a 404', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    [$otherOrganization, $otherEvent] = formScenario();
    $foreign = detailedSubmission($otherOrganization, $otherEvent);

    // Con el evento de su propia ruta y con el de la otra organización.
    $this->actingAs($user)->get(detailUrl($organization, $event, $foreign))->assertNotFound();
    $this->actingAs($user)->get(detailUrl($organization, $otherEvent, $foreign))->assertNotFound();
});

it('CA-009-7: the detail of a submission of another event of the same organization is a 404', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    $submission = detailedSubmission($organization, $otherEvent);

    $this->actingAs($user)->get(detailUrl($organization, $event, $submission))->assertNotFound();
});

it('CA-009-5: a member without submissions.view gets 403 on the detail', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);
    $submission = detailedSubmission($organization, $event);

    $this->actingAs($reviewer)->get(detailUrl($organization, $event, $submission))->assertForbidden();
});

it('CA-009-8: HTML and script in answers, title and participants are escaped', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $submission = detailedSubmission($organization, $event, [
        'title' => '<img src=x onerror=alert(1)>',
        'answers' => [
            'f_resumen' => '<script>alert("xss")</script>',
            'f_nivel' => 'o_basico',
            'f_retirado' => '<b>negrita</b>',
            'f_presentacion' => [],
        ],
    ]);
    CurrentOrganization::run($organization, fn () => SubmissionParticipant::query()
        ->where('submission_id', $submission->id)->where('position', 2)
        ->update(['first_name' => null, 'last_name' => null, 'full_name' => '<script>alert("nombre")</script>']));

    $this->actingAs($user)->get(detailUrl($organization, $event, $submission))
        ->assertOk()
        ->assertDontSee('<script>alert("xss")</script>', false)
        ->assertDontSee('<script>alert("nombre")</script>', false)
        ->assertDontSee('<img src=x onerror=alert(1)>', false)
        ->assertDontSee('<b>negrita</b>', false)
        ->assertSee('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false);
});

it('CA-009-9: an answer to a field removed from the form is still shown with its saved label', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $submission = detailedSubmission($organization, $event);

    $this->actingAs($user)->get(detailUrl($organization, $event, $submission))
        ->assertOk()
        ->assertSee('Campo retirado después')
        ->assertSee('Respuesta antigua');
});

it('CA-009-10: without files.download the detail says there are attachments but offers no download', function (): void {
    [$organization, $event] = formScenario(OrganizationRole::Organizer);
    $submission = detailedSubmission($organization, $event);
    $file = attachedFile($submission);

    // Con `submissions.view` pero sin `files.download`. `events.manage` hace falta porque la lista vive
    // bajo el evento y Filament comprueba el acceso al recurso padre.
    $viewer = Memberships::attach(User::factory()->create(), $organization);
    CurrentOrganization::run($organization, fn () => $viewer->givePermissionTo(['events.manage', 'submissions.view']));

    $this->actingAs($viewer)->get(detailUrl($organization, $event, $submission))
        ->assertOk()
        ->assertSee('Presentación')
        ->assertDontSee('mi-presentacion.pdf')
        ->assertDontSee(route('submission-files.download', ['organization' => $organization->slug, 'file' => $file->id]), false);
});

it('CA-009-6: opening the detail is audited without personal data', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $submission = detailedSubmission($organization, $event);

    $this->actingAs($user)->get(detailUrl($organization, $event, $submission))->assertOk();

    $entry = Activity::query()->where('event', 'submission_viewed')->sole();

    expect($entry->causer_id)->toBe($user->id)
        ->and($entry->subject_id)->toBe($submission->id)
        ->and(json_encode($entry->properties))->not->toContain('ana@example.test')
        ->and(json_encode($entry->properties))->not->toContain('Ana Autora');
});

it('CA-009-6: the list links every row to its detail', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $submission = detailedSubmission($organization, $event);

    // La lista es la sección `submissions` del evento (plan 020, F2).
    $this->actingAs($user)->get(EventResource::getUrl('submissions', ['record' => $event], panel: 'admin', tenant: $organization))
        ->assertOk()
        ->assertSee(detailUrl($organization, $event, $submission), false);
});

it('CA-009-7: an unknown submission id is a 404', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);

    $this->actingAs($user)->get(EventResource::getUrl('submissions', ['record' => $event], panel: 'admin', tenant: $organization).'/'.Str::uuid())
        ->assertNotFound();
});
