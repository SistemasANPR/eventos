<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Submission;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

function submissionsUrl(Organization $organization, Event $event): string
{
    return SubmissionResource::getUrl('index', ['event' => $event], panel: 'admin', tenant: $organization);
}

it('CA-009-1: the list only shows submissions of the route event and its organization, and 404 for an event of another organization', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    [$otherOrganization, $otherEvent] = formScenario();

    $sameOrgEvent = Event::factory()->create(['organization_id' => $organization->id]);
    Submission::factory()->create(['title' => 'Titulo propio', 'organization_id' => $organization->id, 'event_id' => $event->id]);
    Submission::factory()->create(['title' => 'Titulo de otro evento', 'organization_id' => $organization->id, 'event_id' => $sameOrgEvent->id]);
    Submission::factory()->create(['title' => 'Titulo ajeno', 'organization_id' => $otherOrganization->id, 'event_id' => $otherEvent->id]);

    $this->actingAs($user)->get(submissionsUrl($organization, $event))
        ->assertOk()
        ->assertSee('Titulo propio')
        ->assertDontSee('Titulo de otro evento')
        ->assertDontSee('Titulo ajeno');

    $this->actingAs($user)->get(submissionsUrl($organization, $otherEvent))->assertNotFound();
});

it('CA-009-5: a member without submissions.view gets 403 and the resource is not accessible', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);

    $this->actingAs($reviewer)->get(submissionsUrl($organization, $event))->assertForbidden();

    $this->actingAs($reviewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    expect(SubmissionResource::canAccess())->toBeFalse();
});

it('CA-009-7: a submission of another organization never appears in the list of the route event', function (): void {
    [$organization, $event, $user] = formScenario(OrganizationRole::Organizer);
    [$otherOrganization, $otherEvent] = formScenario();
    Submission::factory()->create(['title' => 'Titulo ajeno', 'organization_id' => $otherOrganization->id, 'event_id' => $otherEvent->id]);

    $this->actingAs($user)->get(submissionsUrl($organization, $event))
        ->assertOk()
        ->assertDontSee('Titulo ajeno');
});
