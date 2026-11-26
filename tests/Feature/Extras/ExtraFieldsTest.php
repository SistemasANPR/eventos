<?php

declare(strict_types=1);

use App\Actions\Lodgings\CreateLodging;
use App\Actions\Lodgings\DeleteLodging;
use App\Actions\Lodgings\UpdateLodging;
use App\Actions\Sponsors\CreateSponsor;
use App\Actions\Sponsors\DeleteSponsor;
use App\Actions\Sponsors\UpdateSponsor;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\Pages\ManageEventLodgings;
use App\Filament\Resources\Events\Pages\ManageEventSponsors;
use App\Models\Lodging;
use App\Models\Sponsor;
use App\Support\Extras\ExtraFields;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 030, DA-7: «Datos adicionales» de un hospedaje y de un patrocinador (hasta 10, texto o foto). Las dos entidades
 * comparten `ExtraFields`; cada caso se prueba en ambas.
 */

beforeEach(function (): void {
    Storage::fake(MediaDisk::name());
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

function extraPhoto(string $name = 'foto.png'): UploadedFile
{
    return UploadedFile::fake()->image($name, 30, 30);
}

/** @return array<string, mixed> */
function extraText(string $label, string $value, ?string $id = null): array
{
    return ['id' => $id, 'label' => $label, 'type' => 'text', 'value' => $value];
}

/** @return array<string, mixed> */
function extraImage(string $label, ?UploadedFile $file = null, ?string $id = null): array
{
    return ['id' => $id, 'label' => $label, 'type' => 'image', 'image' => $file];
}

dataset('extra_owners', [
    'hospedaje' => [[CreateLodging::class, UpdateLodging::class, DeleteLodging::class, Lodging::class]],
    'patrocinador' => [[CreateSponsor::class, UpdateSponsor::class, DeleteSponsor::class, Sponsor::class]],
]);

it('CA-030-18: an admin adds a text and a photo detail; the photo goes to the media disk of the event', function (array $classes): void {
    [$create, , , $model] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);

    $record = app($create)->handle($admin, $event, ['name' => 'Uno', 'extra_fields' => [extraText('Teléfono', ' 555-0101 '), extraImage('Fachada', extraPhoto())]]);
    $extras = $model::query()->findOrFail($record->id)->extra_fields;
    $disk = Storage::disk(MediaDisk::name());

    expect($extras)->toHaveCount(2)
        ->and($extras[0])->toMatchArray(['label' => 'Teléfono', 'type' => 'text', 'value' => '555-0101'])
        ->and($extras[0]['id'])->toBeUuid()
        ->and($extras[1])->toMatchArray(['label' => 'Fachada', 'type' => 'image'])
        ->and($extras[1]['path'])->toStartWith("organizations/{$organization->id}/events/{$event->id}/media/")
        ->and($disk->exists($extras[1]['path']))->toBeTrue()
        ->and($disk->getVisibility($extras[1]['path']))->toBe('private');

    // Sin datos adicionales la columna queda nula.
    $plain = app($create)->handle($admin, $event, ['name' => 'Dos']);

    expect($model::query()->findOrFail($plain->id)->extra_fields)->toBeNull();
})->with('extra_owners');

it('CA-030-18: more than 10 details, a bad type, a nameless detail or an empty text is refused and nothing is left behind', function (array $classes): void {
    [$create, , , $model] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);

    $eleven = array_map(fn (int $i): array => extraText("Dato {$i}", 'x'), range(1, 11));
    $disk = Storage::disk(MediaDisk::name());

    expect(ExtraFields::MAX)->toBe(10)
        ->and(validationErrorKeys(fn () => app($create)->handle($admin, $event, ['name' => 'A', 'extra_fields' => $eleven])))->toBe(['extra_fields'])
        ->and(validationErrorKeys(fn () => app($create)->handle($admin, $event, ['name' => 'A', 'extra_fields' => [['label' => 'X', 'type' => 'video', 'value' => 'v']]])))->toContain('extra_fields.0.type')
        ->and(validationErrorKeys(fn () => app($create)->handle($admin, $event, ['name' => 'A', 'extra_fields' => [extraText('  ', 'v')]])))->toContain('extra_fields.0.label')
        ->and(validationErrorKeys(fn () => app($create)->handle($admin, $event, ['name' => 'A', 'extra_fields' => [extraText('Sin texto', '')]])))->toBe(['extra_fields'])
        ->and(validationErrorKeys(fn () => app($create)->handle($admin, $event, ['name' => 'A', 'extra_fields' => [extraImage('Sin foto')]])))->toBe(['extra_fields'])
        ->and($model::query()->count())->toBe(0)
        ->and($disk->allFiles())->toBe([]);

    // Exactamente 10 sí se acepta.
    $ten = app($create)->handle($admin, $event, ['name' => 'B', 'extra_fields' => array_slice($eleven, 0, 10)]);

    expect($model::query()->findOrFail($ten->id)->extra_fields)->toHaveCount(10);
})->with('extra_owners');

it('CA-030-18: a fake photo is refused in the details field and a failed save leaves no orphan file', function (array $classes): void {
    [$create, , , $model] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $fake = UploadedFile::fake()->createWithContent('a.png', 'esto no es una imagen');

    expect(validationErrorKeys(fn () => app($create)->handle($admin, $event, ['name' => 'A', 'extra_fields' => [extraImage('Mala', $fake)]])))->toBe(['extra_fields'])
        ->and(validationErrorKeys(fn () => app($create)->handle($admin, $event, [
            'name' => 'B',
            'extra_fields' => [extraImage('Buena', extraPhoto()), extraImage('Mala', $fake)],
        ])))->toBe(['extra_fields'])
        // Un error en otro campo después de guardar fotos válidas tampoco deja archivos.
        ->and(validationErrorKeys(fn () => app($create)->handle($admin, $event, [
            'name' => '',
            'extra_fields' => [extraImage('Buena', extraPhoto())],
        ])))->toContain('name')
        ->and($model::query()->count())->toBe(0)
        ->and(Storage::disk(MediaDisk::name())->allFiles())->toBe([]);
})->with('extra_owners');

it('CA-030-19: editing keeps, changes and removes details; replaced and removed photos are deleted from the disk', function (array $classes): void {
    [$create, $update, , $model] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $disk = Storage::disk(MediaDisk::name());

    $record = app($create)->handle($admin, $event, ['name' => 'Uno', 'extra_fields' => [
        extraText('Teléfono', '111'),
        extraImage('Fachada', extraPhoto('a.png')),
        extraImage('Recepción', extraPhoto('b.png')),
    ]]);
    [$phone, $facade, $lobby] = $model::query()->findOrFail($record->id)->extra_fields;

    expect($disk->allFiles())->toHaveCount(2);

    // Sin la clave, nada cambia.
    app($update)->handle($admin, $record, ['name' => 'Renombrado']);

    expect($model::query()->findOrFail($record->id)->extra_fields)->toBe([$phone, $facade, $lobby])
        ->and($disk->allFiles())->toHaveCount(2);

    // Cambia el texto, la foto «Fachada» se conserva sin subir otra y «Recepción» se quita.
    app($update)->handle($admin, $record, ['extra_fields' => [
        extraText('Teléfono', '222', $phone['id']),
        extraImage('Fachada nueva', null, $facade['id']),
    ]]);
    $after = $model::query()->findOrFail($record->id)->extra_fields;

    expect($after)->toHaveCount(2)
        ->and($after[0])->toMatchArray(['id' => $phone['id'], 'value' => '222'])
        ->and($after[1])->toMatchArray(['id' => $facade['id'], 'label' => 'Fachada nueva', 'path' => $facade['path']])
        ->and($disk->exists($facade['path']))->toBeTrue()
        ->and($disk->exists($lobby['path']))->toBeFalse();

    // Subir otra foto reemplaza la anterior.
    app($update)->handle($admin, $record, ['extra_fields' => [
        extraText('Teléfono', '222', $phone['id']),
        extraImage('Fachada nueva', extraPhoto('c.png'), $facade['id']),
    ]]);
    $replaced = $model::query()->findOrFail($record->id)->extra_fields[1];

    expect($replaced['path'])->not->toBe($facade['path'])
        ->and($disk->exists($replaced['path']))->toBeTrue()
        ->and($disk->exists($facade['path']))->toBeFalse()
        ->and($disk->allFiles())->toHaveCount(1);

    // Pasar la foto a texto la borra; vaciar la lista deja la columna nula.
    app($update)->handle($admin, $record, ['extra_fields' => [extraText('Fachada', 'Frente al mar', $facade['id'])]]);

    expect($disk->allFiles())->toBe([]);

    app($update)->handle($admin, $record, ['extra_fields' => []]);

    expect($model::query()->findOrFail($record->id)->extra_fields)->toBeNull();
})->with('extra_owners');

it('CA-030-19: a refused edit keeps the saved details and photos untouched', function (array $classes): void {
    [$create, $update, , $model] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $disk = Storage::disk(MediaDisk::name());
    $record = app($create)->handle($admin, $event, ['name' => 'Uno', 'extra_fields' => [extraImage('Fachada', extraPhoto())]]);
    $before = $model::query()->findOrFail($record->id)->extra_fields;

    // Una foto buena nueva y otra falsa: nada se guarda y la foto nueva no queda huérfana.
    expect(validationErrorKeys(fn () => app($update)->handle($admin, $record, ['extra_fields' => [
        extraImage('Fachada', extraPhoto('n.png'), $before[0]['id']),
        extraImage('Mala', UploadedFile::fake()->createWithContent('a.png', 'no es imagen')),
    ]])))->toBe(['extra_fields'])
        ->and($model::query()->findOrFail($record->id)->extra_fields)->toBe($before)
        ->and($disk->allFiles())->toHaveCount(1)
        ->and($disk->exists($before[0]['path']))->toBeTrue();
})->with('extra_owners');

it('CA-030-19: an id of a detail that is not of this record is treated as a new one; a repeated id counts once', function (array $classes): void {
    [$create, $update, , $model] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $mine = app($create)->handle($admin, $event, ['name' => 'Mío', 'extra_fields' => [extraImage('Foto', extraPhoto())]]);
    $other = app($create)->handle($admin, $event, ['name' => 'Otro', 'extra_fields' => [extraImage('Foto', extraPhoto('o.png'))]]);
    $foreign = $model::query()->findOrFail($other->id)->extra_fields[0];

    // Reusar el id (y sin subir foto) de un dato ajeno no hereda su foto: pide una.
    expect(validationErrorKeys(fn () => app($update)->handle($admin, $mine, ['extra_fields' => [extraImage('Robada', null, $foreign['id'])]])))->toBe(['extra_fields'])
        ->and($model::query()->findOrFail($other->id)->extra_fields[0])->toBe($foreign);

    $own = $model::query()->findOrFail($mine->id)->extra_fields[0];
    app($update)->handle($admin, $mine, ['extra_fields' => [extraText('Uno', 'a', $own['id']), extraText('Dos', 'b', $own['id'])]]);
    $ids = array_column($model::query()->findOrFail($mine->id)->extra_fields, 'id');

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->toBe($own['id'])
        ->and($ids[1])->not->toBe($own['id']);
})->with('extra_owners');

it('CA-030-19: deleting the record deletes the files of its photo details', function (array $classes): void {
    [$create, , $delete, $model] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $gone = app($create)->handle($admin, $event, ['name' => 'Se va', 'extra_fields' => [extraImage('A', extraPhoto()), extraText('B', 'texto'), extraImage('C', extraPhoto('c.png'))]]);
    app($create)->handle($admin, $event, ['name' => 'Se queda', 'extra_fields' => [extraImage('A', extraPhoto('k.png'))]]);

    expect(Storage::disk(MediaDisk::name())->allFiles())->toHaveCount(3);

    app($delete)->handle($admin, $gone);

    expect($model::query()->count())->toBe(1)
        ->and(Storage::disk(MediaDisk::name())->allFiles())->toHaveCount(1);
})->with('extra_owners');

it('CA-030-20: an organizer edits the details; a reviewer and an admin of another organization cannot', function (array $classes): void {
    [$create, $update, , $model] = $classes;
    [$organization, $event, $organizer, $admin] = formScenario(OrganizationRole::Organizer);
    CurrentOrganization::set($organization);
    $record = app($create)->handle($organizer, $event, ['name' => 'Uno', 'extra_fields' => [extraText('Teléfono', '1')]]);

    app($update)->handle($organizer, $record, ['extra_fields' => [extraText('Teléfono', '2')]]);

    expect($model::query()->findOrFail($record->id)->extra_fields[0]['value'])->toBe('2');

    [, $reviewerEvent, $reviewer] = formScenario(OrganizationRole::Reviewer);

    expect(fn () => app($create)->handle($reviewer, $reviewerEvent, ['name' => 'X', 'extra_fields' => [extraText('A', 'b')]]))->toThrow(AuthorizationException::class);
})->with('extra_owners');

it('CA-030-20: an admin of another organization cannot touch the details of this one', function (array $classes): void {
    [$create, $update, , $model] = $classes;
    [$organizationA, $eventA, $adminA] = formScenario();
    [, , $adminB] = formScenario();
    CurrentOrganization::set($organizationA);
    $record = app($create)->handle($adminA, $eventA, ['name' => 'Uno', 'extra_fields' => [extraText('Teléfono', '1')]]);

    expect(fn () => app($update)->handle($adminB, $record, ['extra_fields' => []]))->toThrow(AuthorizationException::class)
        ->and(fn () => app($create)->handle($adminB, $eventA, ['name' => 'X', 'extra_fields' => [extraText('A', 'b')]]))->toThrow(AuthorizationException::class)
        ->and($model::query()->findOrFail($record->id)->extra_fields)->toHaveCount(1);
})->with('extra_owners');

it('CA-030-18: in the lodging panel the admin adds text and photo details, edits them and the saved photo is kept', function (): void {
    [$organization, $event, $admin] = formScenario();
    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    $component = Livewire::test(ManageEventLodgings::class, ['record' => $event->id]);
    $component->callAction('createLodging', ['name' => 'Hotel Playa', 'extra_fields' => [
        ['label' => 'Teléfono', 'type' => 'text', 'value' => '555'],
        ['label' => 'Fachada', 'type' => 'image', 'image' => [extraPhoto()]],
    ]])->assertHasNoActionErrors();

    $lodging = Lodging::query()->where('event_id', $event->id)->firstOrFail();
    $saved = collect($lodging->extra_fields)->sortBy(fn (array $item): int => $item['type'] === 'text' ? 0 : 1)->values()->all();

    expect($saved)->toHaveCount(2)
        ->and(Storage::disk(MediaDisk::name())->exists($saved[1]['path']))->toBeTrue();

    // Al editar vuelven con su id y la marca de que ya tienen foto; guardar sin subir otra la conserva.
    $component->callAction(TestAction::make('editLodging')->table($lodging), ['name' => 'Hotel Playa', 'extra_fields' => [
        ['id' => $saved[0]['id'], 'label' => 'Teléfono', 'type' => 'text', 'value' => '999'],
        ['id' => $saved[1]['id'], 'label' => 'Fachada', 'type' => 'image', 'has_image' => true],
    ]])->assertHasNoActionErrors();

    $after = $lodging->fresh()->extra_fields;

    expect($after[0]['value'])->toBe('999')
        ->and($after[1]['path'])->toBe($saved[1]['path'])
        ->and(Storage::disk(MediaDisk::name())->exists($saved[1]['path']))->toBeTrue();

    // Una foto falsa muestra el error y no guarda nada.
    $component->callAction('createLodging', ['name' => 'Otro', 'extra_fields' => [
        ['label' => 'Mala', 'type' => 'image', 'image' => [UploadedFile::fake()->createWithContent('a.png', 'esto no es una imagen')]],
    ]])->assertHasActionErrors();

    expect(Lodging::query()->count())->toBe(1);
});

it('CA-030-18: in the sponsor panel the admin adds a photo detail and removes it by leaving the list empty', function (): void {
    [$organization, $event, $admin] = formScenario();
    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    $component = Livewire::test(ManageEventSponsors::class, ['record' => $event->id]);
    $component->callAction('createSponsor', ['name' => 'Acme', 'extra_fields' => [
        ['label' => 'Stand', 'type' => 'image', 'image' => [extraPhoto()]],
    ]])->assertHasNoActionErrors();

    $sponsor = Sponsor::query()->where('event_id', $event->id)->firstOrFail();
    $path = $sponsor->extra_fields[0]['path'];

    expect(Storage::disk(MediaDisk::name())->exists($path))->toBeTrue();

    $component->callAction(TestAction::make('editSponsor')->table($sponsor), ['name' => 'Acme', 'extra_fields' => []])->assertHasNoActionErrors();

    expect($sponsor->fresh()->extra_fields)->toBeNull()
        ->and(Storage::disk(MediaDisk::name())->exists($path))->toBeFalse();
});
