<?php

declare(strict_types=1);

/*
 * Ayudas compartidas por las pruebas de formularios (plan 005): escenario con organización y evento,
 * campos de ejemplo de cada tipo y un lector de errores de validación. Se cargan con `require_once`
 * desde cada prueba para poder correr cualquiera de ellas por separado.
 */

use App\Actions\Events\CreateEvent;
use App\Actions\Forms\CreateFormDefinition;
use App\Actions\Forms\PublishFormDefinition;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Forms\FormDefinitionResource;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Memberships;

/** Lista de formularios de un evento (plan 020): `/admin/{tenant}/events/{event}/form-definitions`. */
function eventFormsUrl(Organization $organization, Event $event): string
{
    return EventResource::getUrl('form-definitions', ['record' => $event], panel: 'admin', tenant: $organization);
}

/** Página de un formulario bajo su evento; `create` no lleva formulario. */
function eventFormUrl(Organization $organization, Event $event, string $page, ?FormDefinition $form = null): string
{
    $parameters = ['event' => $event];

    if ($form !== null) {
        $parameters['record'] = $form;
    }

    return FormDefinitionResource::getUrl($page, $parameters, panel: 'admin', tenant: $organization);
}

/**
 * Simula la ruta `/events/{event}/...` de una página anidada para `Livewire::test`: Filament lee el
 * evento padre de la petición original, que en una prueba de Livewire no tiene ruta.
 */
function withEventRoute(Event $event): void
{
    $uri = "/events/{$event->id}/form-definitions";
    $request = Request::create($uri);
    $route = new Route('GET', '/events/{event}/form-definitions', fn () => null);
    $route->bind($request);
    $request->setRouteResolver(fn (): Route => $route);

    app()->instance('originalRequest', $request);
}

/** @return array{0: Organization, 1: Event, 2: User, 3: User} organización con roles, evento (es, en), el usuario con el rol indicado y un administrador (el mismo si el rol es administrador) */
function formScenario(OrganizationRole $role = OrganizationRole::OrgAdmin): array
{
    $organization = Memberships::organization();
    $user = Memberships::attach(User::factory()->create(), $organization, $role);
    $admin = $role === OrganizationRole::OrgAdmin ? $user : Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
    $event = app(CreateEvent::class)->handle($admin, $organization, [
        'name' => ['es' => 'Congreso', 'en' => 'Congress'],
        'slug' => 'congreso-'.Str::lower(Str::random(6)),
        'type' => 'congress',
        'starts_at' => '2027-03-10 09:00',
        'ends_at' => '2027-03-12 18:00',
        'timezone' => 'America/Mexico_City',
        'locales' => ['es', 'en'],
        'default_locale' => 'es',
        'currency' => 'MXN',
    ]);

    return [$organization, $event, $user, $admin];
}

/**
 * Un campo válido de cada tipo.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function formField(string $type, array $overrides = []): array
{
    $config = match ($type) {
        'short_text' => ['minLength' => 0, 'maxLength' => 200],
        'long_text' => ['minLength' => 0, 'maxLength' => 2000],
        'number' => ['min' => 0, 'max' => 10],
        'single_choice' => ['options' => formOptions(['Sí', 'No'])],
        'multiple_choice' => ['options' => formOptions(['Uno', 'Dos', 'Tres']), 'maxSelections' => 2],
        'file' => ['accept' => ['pdf', 'pptx'], 'maxFiles' => 1],
        default => [],
    };

    return array_replace([
        'type' => $type,
        'required' => true,
        'label' => ['es' => 'Campo '.$type, 'en' => 'Field '.$type],
        'help' => ['es' => 'Ayuda '.$type],
        'config' => $config,
    ], $overrides);
}

/**
 * @param  list<string>  $labels
 * @return list<array{label: array<string, string>}>
 */
function formOptions(array $labels): array
{
    return array_map(static fn (string $label): array => ['label' => ['es' => $label, 'en' => $label.' (en)']], $labels);
}

/** @return list<string> */
function allFieldTypes(): array
{
    return ['short_text', 'long_text', 'number', 'single_choice', 'multiple_choice', 'checkbox', 'date', 'country', 'file'];
}

/**
 * Ejecuta la acción y devuelve las rutas de los errores de validación (vacío si no hubo ninguno), para
 * comprobar que se rechazó POR LA RAZÓN ESPERADA y no por otra.
 *
 * @return list<string>
 */
function validationErrorKeys(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return array_keys($exception->errors());
    }

    return [];
}

/** @param  list<array<string, mixed>>  $fields */
function createForm(User $actor, Event $event, array $fields, string $name = 'Formulario de sesiones'): FormDefinition
{
    return app(CreateFormDefinition::class)->handle($actor, $event, ['name' => $name, 'fields' => $fields]);
}

/** Asigna a la convocatoria un formulario publicado y válido de su evento (plan 025: sin formulario no se publica). */
function assignPublishedForm(User $actor, CallForSubmissions $call): FormDefinition
{
    $event = CurrentOrganization::run(
        Organization::query()->findOrFail($call->organization_id),
        fn (): Event => Event::query()->findOrFail($call->event_id),
    );
    $form = createForm($actor, $event, [formField('short_text')]);

    $published = app(PublishFormDefinition::class)->handle($actor, $form, $call);
    // La Action relee la convocatoria: se actualiza la instancia del llamador para que vea el formulario.
    $call->refresh();

    return $published;
}
