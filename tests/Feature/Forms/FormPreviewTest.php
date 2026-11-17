<?php

declare(strict_types=1);

use App\Actions\Forms\UpdateFormDefinition;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Forms\Pages\PreviewFormDefinition;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\User;
use App\Support\Forms\FormPreviewProps;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Support\Memberships;

uses(RefreshDatabase::class);
require_once __DIR__.'/../../Support/form-helpers.php';

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** Un miembro sin permisos de organizador al que se le concede solo `forms.view`. */
function viewerOf(Organization $organization): User
{
    $viewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    Permission::findOrCreate('forms.view', 'web');
    CurrentOrganization::run($organization, fn () => $viewer->givePermissionTo('forms.view'));

    return $viewer->refresh();
}

function previewUrl(Organization $organization, FormDefinition $form): string
{
    return "/admin/{$organization->slug}/forms/form-definitions/{$form->id}/preview";
}

it('CA-005-11: reordering the fields keeps every key and leaves contiguous positions that survive a reload', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text'), formField('long_text'), formField('number')]);
    $keys = array_column($form->fields, 'key');

    $reordered = [$form->fields[2], $form->fields[0], $form->fields[1]];
    app(UpdateFormDefinition::class)->handle($admin, $form, ['name' => $form->name, 'fields' => $reordered]);

    $stored = CurrentOrganization::run($organization, fn (): FormDefinition => FormDefinition::query()->findOrFail($form->id));

    expect(array_column($stored->fields, 'key'))->toBe([$keys[2], $keys[0], $keys[1]])
        ->and(array_column($stored->fields, 'position'))->toBe([1, 2, 3])
        ->and($stored->version)->toBe(2);
});

it('CA-005-12: the preview data has the active fields in order, the asked language and its interface texts', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text'), formField('long_text'), formField('number')]);
    $form = CurrentOrganization::run($organization, fn (): FormDefinition => FormDefinition::query()->findOrFail($form->id));
    $fields = $form->fields;
    $fields[1]['retiredAt'] = '2027-01-01T00:00:00Z';
    $fields[2]['position'] = 1;
    $fields[0]['position'] = 2;
    $form->forceFill(['fields' => $fields]);

    $english = app(FormPreviewProps::class)->handle($form, $event, 'en');
    $unknown = app(FormPreviewProps::class)->handle($form, $event, 'fr');

    expect(array_column($english['fields'], 'key'))->toBe([$fields[2]['key'], $fields[0]['key']])
        ->and($english['locale'])->toBe('en')
        ->and($english['defaultLocale'])->toBe('es')
        ->and($english['translations']['forms']['renderer']['submit'])->toBe('Submit proposal')
        ->and($unknown['locale'])->toBe('es')
        ->and($unknown['translations']['forms']['renderer']['submit'])->toBe('Enviar propuesta');
});

it('CA-005-13: without a session the preview redirects to the panel login', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text')]);

    $this->get(previewUrl($organization, $form))->assertRedirect();
});

it('CA-005-13: the form of another organization answers 404 in the preview', function (): void {
    [$organizationA, , $adminA] = formScenario();
    [, $eventB, $adminB] = formScenario();
    $foreign = createForm($adminB, $eventB, [formField('short_text')]);

    $this->actingAs($adminA)->get(previewUrl($organizationA, $foreign))->assertNotFound();
});

it('CA-005-13: a member with forms.view sees the preview and the page only offers the renderer', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text')]);
    $viewer = viewerOf($organization);

    $response = $this->actingAs($viewer)->get(previewUrl($organization, $form));

    $response->assertOk()->assertSee('data-form-preview', false)->assertSee($form->fields[0]['key'], false);
});

it('CA-005-13: a member without forms.view or forms.manage cannot open the preview', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text')]);
    $reviewer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);

    expect($this->actingAs($reviewer)->get(previewUrl($organization, $form))->status())->toBeIn([403, 404]);
});

it('CA-005-12: in the panel the preview changes language and ignores a language the event does not have', function (): void {
    [$organization, $event, $admin] = formScenario();
    $form = createForm($admin, $event, [formField('short_text')]);
    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    Livewire::test(PreviewFormDefinition::class, ['record' => $form->id])
        ->set('locale', 'en')
        ->assertSee('Submit proposal', false)
        ->set('locale', 'fr')
        ->assertSee('Enviar propuesta', false);
});
