<?php

declare(strict_types=1);

/*
 * Ayudas compartidas por las pruebas de formularios (plan 005): escenario con organización y evento,
 * campos de ejemplo de cada tipo y un lector de errores de validación. Se cargan con `require_once`
 * desde cada prueba para poder correr cualquiera de ellas por separado.
 */

use App\Actions\Events\CreateEvent;
use App\Actions\Forms\CreateFormDefinition;
use App\Enums\OrganizationRole;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Memberships;

/** @return array{0: Organization, 1: Event, 2: User} organización con roles, evento (es, en) y el usuario con el rol indicado */
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

    return [$organization, $event, $user];
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
