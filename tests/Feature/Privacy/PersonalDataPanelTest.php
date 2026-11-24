<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Pages\Privacy\PersonalData;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Memberships;
use Tests\Support\PersonFixtures;

/*
 * Pantalla «Datos de una persona» (plan 014, H-014-4). Es delgada: las reglas viven en las Actions
 * (`AnonymizePersonTest`, `ExportPersonDataTest`); aquí se comprueba quién la ve, que exportar y anonimizar
 * funcionan desde ella y que la confirmación fuerte se exige.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PersonFixtures::prepare();
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

function personalDataSession(Organization $organization, User $user): void
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
}

it('CA-014-30: only who has personal_data.manage can open the page', function (OrganizationRole $role, bool $allowed): void {
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);
    personalDataSession($organization, $user);

    expect(PersonalData::canAccess())->toBe($allowed);

    $allowed
        ? Livewire::test(PersonalData::class)->assertSuccessful()->assertSee(__('privacy_admin.personal_data.actions.export'))
        : $this->get(PersonalData::getUrl(tenant: $organization))->assertForbidden();
})->with([
    'org_admin' => [OrganizationRole::OrgAdmin, true],
    'organizer' => [OrganizationRole::Organizer, false],
    'reviewer' => [OrganizationRole::Reviewer, false],
]);

it('CA-014-20: exporting from the page leaves a private file and a download link in the notification', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    PersonFixtures::person($organization);
    personalDataSession($organization, $admin);

    Livewire::test(PersonalData::class)
        ->fillForm(['email' => PersonFixtures::EMAIL, 'reason' => 'data_subject_request'])
        ->call('export')
        ->assertHasNoFormErrors()
        ->assertNotified(__('privacy_admin.personal_data.notifications.exported'));

    expect(Storage::disk((string) config('filesystems.private_uploads_disk'))->allFiles("organizations/{$organization->id}/exports"))->toHaveCount(1);
});

it('CA-014-23: a person that does not exist in this organization gets a not-found notice and nothing is written', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    personalDataSession($organization, $admin);

    Livewire::test(PersonalData::class)
        ->fillForm(['email' => 'nadie@example.test', 'reason' => 'data_subject_request'])
        ->call('export')
        ->assertNotified(__('privacy_admin.personal_data.notifications.not_found'));

    expect(DB::table('activity_log')->where('event', 'person_data_exported')->count())->toBe(0);
});

it('CA-014-24: anonymizing asks for the email again; a wrong confirmation changes nothing', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    personalDataSession($organization, $admin);

    Livewire::test(PersonalData::class)
        ->fillForm(['email' => PersonFixtures::EMAIL, 'reason' => 'data_subject_request'])
        ->call('anonymize', ['confirmation' => 'otro@example.test']);

    expect(DB::table('contacts')->where('id', $person['contact']->id)->value('anonymized_at'))->toBeNull();
});

it('CA-014-21: anonymizing from the page with the confirmation anonymizes the person and reports it', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    personalDataSession($organization, $admin);

    Livewire::test(PersonalData::class)
        ->fillForm(['email' => PersonFixtures::EMAIL, 'reason' => 'consent_withdrawn'])
        ->call('anonymize', ['confirmation' => PersonFixtures::EMAIL])
        ->assertNotified(__('privacy_admin.personal_data.notifications.anonymized'));

    expect(DB::table('contacts')->where('id', $person['contact']->id)->value('full_name'))->toBe('Persona anonimizada');
});

it('CA-014-21: the page translates the refusal for the last administrator', function (): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    $actor = null;
    CurrentOrganization::run($organization, function () use ($organization, $person, &$actor): void {
        foreach (User::query()->activeMembersOf($organization)->role(OrganizationRole::OrgAdmin->value)->get() as $existing) {
            $organization->users()->updateExistingPivot($existing->id, ['status' => 'deactivated', 'deactivated_at' => now()]);
        }
        $person['user']->assignRole(OrganizationRole::OrgAdmin->value);
        $actor = User::factory()->create();
        $organization->users()->attach($actor->id, ['status' => 'active']);
        $actor->givePermissionTo('personal_data.manage');
    });
    personalDataSession($organization, $actor);

    Livewire::test(PersonalData::class)
        ->fillForm(['email' => PersonFixtures::EMAIL, 'reason' => 'data_subject_request'])
        ->call('anonymize', ['confirmation' => PersonFixtures::EMAIL])
        ->assertNotified(__('privacy_admin.personal_data.notifications.last_admin'));
});
