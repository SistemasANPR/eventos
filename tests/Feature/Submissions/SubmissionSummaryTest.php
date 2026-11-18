<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\Pages\ManageEventSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Support\SubmissionSummary\SubmissionSummary;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/**
 * Abre la sección de propuestas del evento como `$user` (plan 020, F2).
 *
 * @return Testable<ManageEventSubmissions>
 */
function summaryPage(Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test(ManageEventSubmissions::class, ['record' => $event->id]);
}

/**
 * Otra propuesta del mismo evento con la convocatoria, el tipo, el formulario y el aviso de `$base` (la
 * factory crea uno nuevo por propuesta y el aviso es único por evento y versión).
 *
 * @param  array<string, mixed>  $overrides
 */
function summarySibling(Submission $base, array $overrides = []): Submission
{
    return Submission::factory()->create([
        'organization_id' => $base->organization_id,
        'event_id' => $base->event_id,
        'call_for_submissions_id' => $base->call_for_submissions_id,
        'submission_type_id' => $base->submission_type_id,
        'form_definition_id' => $base->form_definition_id,
        'track_id' => $base->track_id,
        'privacy_notice_version_id' => $base->privacy_notice_version_id,
        ...$overrides,
    ]);
}

it('CA-009-20: the summary counts by track, type and day in the event timezone, only for this event and organization', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    [$otherOrganization, $otherEvent] = formScenario();
    $otherTrack = Track::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id]);

    // El evento está en America/Mexico_City (UTC-6): las 03:00 UTC del día 11 son las 21:00 del día 10.
    $first = Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'submitted_at' => '2027-01-11 03:00:00']);
    summarySibling($first, ['submitted_at' => '2027-01-10 15:00:00']);
    summarySibling($first, ['submitted_at' => '2027-01-12 15:00:00', 'track_id' => $otherTrack->id]);

    // No cuentan: otro evento de la misma organización, otra organización y un invitado (plan 018).
    Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => Event::factory()->create(['organization_id' => $organization->id])->id]);
    Submission::factory()->create(['organization_id' => $otherOrganization->id, 'event_id' => $otherEvent->id]);
    Submission::factory()->invited()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id,
        'submission_type_id' => $first->submission_type_id, 'form_definition_id' => $first->form_definition_id, 'track_id' => $first->track_id,
    ]);

    CurrentOrganization::set($organization);
    $summary = SubmissionSummary::for($event);

    expect($summary['total'])->toBe(3)
        ->and(array_sum(array_column($summary['byTrack'], 'count')))->toBe(3)
        ->and(collect($summary['byTrack'])->pluck('count')->sort()->values()->all())->toBe([1, 2])
        ->and($summary['byType'])->toHaveCount(1)
        ->and($summary['byType'][0]['count'])->toBe(3)
        ->and(collect($summary['byDay'])->pluck('count', 'day')->all())->toBe(['2027-01-10' => 2, '2027-01-12' => 1]);
});

it('CA-009-20: the summary view prints the counts by track, type and day, escaped', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    $submission = Submission::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'submitted_at' => '2027-01-10 15:00:00']);
    summarySibling($submission, ['submitted_at' => '2027-01-10 16:00:00']);

    CurrentOrganization::set($organization);
    CurrentOrganization::run($organization, fn () => $submission->track->forceFill(['name' => ['es' => '<b>Eje</b>']])->save());
    $html = view('filament.submissions.summary', ['summary' => SubmissionSummary::for($event)])->render();
    CurrentOrganization::forget();

    expect($html)->toContain(__('submission_panel.summary.by_track'))
        ->and($html)->toContain(__('submission_panel.summary.by_type'))
        ->and($html)->toContain(__('submission_panel.summary.by_day'))
        ->and($html)->toContain('2027-01-10')
        ->and($html)->toContain('&lt;b&gt;Eje&lt;/b&gt;')
        ->and($html)->not->toContain('<b>Eje</b>');

    // La acción está disponible en la sección de propuestas del evento para quien puede verlas.
    summaryPage($organization, $user, $event)->assertActionVisible('summary');
});

it('CA-009-21: an event without submissions shows an explanatory empty state, not an error', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);

    CurrentOrganization::set($organization);
    $summary = SubmissionSummary::for($event);
    $html = view('filament.submissions.summary', ['summary' => $summary])->render();
    CurrentOrganization::forget();

    expect($summary)->toBe(['total' => 0, 'byTrack' => [], 'byType' => [], 'byDay' => []])
        ->and($html)->toContain(__('submission_panel.summary.empty'));
});
