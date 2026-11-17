<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ManageEventSubmissions;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 020, F2: la sub-navegación del evento ofrece Resumen, Formularios y Propuestas (DA-2).
 * Cada prueba usa un solo rol: la caché de permisos de Spatie se comparte dentro del proceso.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

it('CA-020-15: the event view offers Summary, Forms and Submissions to an admin', function (): void {
    [$organization, $event, $admin] = formScenario();

    $this->actingAs($admin)->get(EventResource::getUrl('view', ['record' => $event], panel: 'admin', tenant: $organization))
        ->assertOk()
        ->assertSee(__('events.sub_navigation.summary'))
        ->assertSee(eventFormsUrl($organization, $event), false)
        ->assertSee(EventResource::getUrl('submissions', ['record' => $event], panel: 'admin', tenant: $organization), false);
});

it('CA-020-15: the submissions page of the event is reachable for an admin', function (): void {
    [$organization, $event, $admin] = formScenario();

    $this->actingAs($admin)->get(EventResource::getUrl('submissions', ['record' => $event], panel: 'admin', tenant: $organization))->assertOk();
});

it('CA-020-15: the sub-navigation offers no Submissions entry to who cannot see them', function (): void {
    [$organization, $event, $reviewer] = formScenario(OrganizationRole::Reviewer);
    Auth::login($reviewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    expect(ManageEventSubmissions::canAccess(['record' => $event]))->toBeFalse();
});
