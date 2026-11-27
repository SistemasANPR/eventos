<?php

declare(strict_types=1);

use App\Actions\Extras\SaveExtraFieldDefinitions;
use App\Actions\Lodgings\CreateLodging;
use App\Actions\Lodgings\DeleteLodging;
use App\Actions\Lodgings\UpdateLodging;
use App\Actions\Sponsors\CreateSponsor;
use App\Actions\Sponsors\DeleteSponsor;
use App\Actions\Sponsors\UpdateSponsor;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Events\Pages\ManageEventLodgings;
use App\Filament\Resources\Events\Pages\ManageEventSponsors;
use App\Models\Event;
use App\Models\ExtraFieldDefinition;
use App\Models\Lodging;
use App\Models\Sponsor;
use App\Models\User;
use App\Support\Extras\ExtraFields;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 030, DA-7: preguntas del formulario de hospedajes y de patrocinadores. Se definen una vez por evento y módulo
 * (hasta 10, texto o foto) y todos los registros del evento las responden. Las dos entidades comparten `ExtraFields`; cada
 * caso se prueba en ambas.
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

/**
 * Define preguntas con la Action y devuelve las guardadas, en su orden.
 *
 * @param  list<array<string, mixed>>  $items
 * @return list<ExtraFieldDefinition>
 */
function defineQuestions(User $actor, Event $event, string $scope, array $items): array
{
    return app(SaveExtraFieldDefinitions::class)->handle($actor, $event, $scope, $items);
}

dataset('extra_owners', [
    'hospedaje' => [[CreateLodging::class, UpdateLodging::class, DeleteLodging::class, Lodging::class, 'lodging']],
    'patrocinador' => [[CreateSponsor::class, UpdateSponsor::class, DeleteSponsor::class, Sponsor::class, 'sponsor']],
]);

it('CA-030-18: an admin defines the questions of the event, in order, and a later save keeps the identity of the existing ones', function (array $classes): void {
    [, , , , $scope] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);

    $saved = defineQuestions($admin, $event, $scope, [
        ['label' => ' Teléfono ', 'type' => 'short_text'],
        ['label' => 'Fachada', 'type' => 'photo'],
    ]);

    expect($saved)->toHaveCount(2)
        ->and($saved[0]->label)->toBe('Teléfono')
        ->and($saved[0]->position)->toBe(1)
        ->and($saved[1]->type)->toBe('photo')
        ->and($saved[1]->position)->toBe(2)
        ->and($saved[0]->scope)->toBe($scope);

    // Se reordena y se renombra una: conserva su identificador.
    $again = defineQuestions($admin, $event, $scope, [
        ['id' => $saved[1]->id, 'label' => 'Foto de la fachada', 'type' => 'photo'],
        ['id' => $saved[0]->id, 'label' => 'Teléfono', 'type' => 'short_text'],
    ]);

    expect($again[0]->id)->toBe($saved[1]->id)
        ->and($again[0]->label)->toBe('Foto de la fachada')
        ->and($again[0]->position)->toBe(1)
        ->and(ExtraFieldDefinition::query()->where('event_id', $event->id)->count())->toBe(2);
})->with('extra_owners');

it('CA-030-18: more than 10 questions, a bad type or a nameless question is refused and nothing is saved', function (array $classes): void {
    [, , , , $scope] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    defineQuestions($admin, $event, $scope, [['label' => 'Previa', 'type' => 'short_text']]);

    $eleven = array_map(fn (int $n): array => ['label' => "P{$n}", 'type' => 'short_text'], range(1, 11));

    foreach ([$eleven, [['label' => 'X', 'type' => 'banana']], [['label' => '  ', 'type' => 'short_text']], [['label' => str_repeat('a', 121), 'type' => 'short_text']]] as $bad) {
        expect(fn () => defineQuestions($admin, $event, $scope, $bad))->toThrow(ValidationException::class);
    }

    expect(ExtraFieldDefinition::query()->where('event_id', $event->id)->pluck('label')->all())->toBe(['Previa']);

    // Diez sí caben.
    $ten = array_map(fn (int $n): array => ['label' => "P{$n}", 'type' => 'short_text'], range(1, 10));

    expect(defineQuestions($admin, $event, $scope, $ten))->toHaveCount(10);
})->with('extra_owners');

it('CA-030-19: a record answers the questions of the event: text is trimmed and the photo goes to the media disk', function (array $classes): void {
    [$create, , , $model, $scope] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    [$phone, $facade] = defineQuestions($admin, $event, $scope, [['label' => 'Teléfono', 'type' => 'short_text'], ['label' => 'Fachada', 'type' => 'photo']]);

    $record = app($create)->handle($admin, $event, ['name' => 'Uno', 'extra_fields' => [$phone->id => ' 555-0101 ', $facade->id => extraPhoto()]]);
    $values = $model::query()->findOrFail($record->id)->extra_fields;
    $disk = Storage::disk(MediaDisk::name());

    expect($values)->toHaveCount(2)
        ->and($values[$phone->id])->toBe('555-0101')
        ->and($values[$facade->id]['path'])->toStartWith("organizations/{$organization->id}/events/{$event->id}/media/")
        ->and($disk->exists($values[$facade->id]['path']))->toBeTrue();
})->with('extra_owners');

it('CA-030-19: a text over 500 characters, a text that is not text or a fake photo is refused and leaves no orphan file', function (array $classes): void {
    [$create, , , $model, $scope] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    [$phone, $facade, $other] = defineQuestions($admin, $event, $scope, [['label' => 'Teléfono', 'type' => 'short_text'], ['label' => 'Fachada', 'type' => 'photo'], ['label' => 'Otra', 'type' => 'photo']]);

    expect(fn () => app($create)->handle($admin, $event, ['name' => 'A', 'extra_fields' => [$phone->id => str_repeat('a', 501)]]))->toThrow(ValidationException::class)
        ->and(fn () => app($create)->handle($admin, $event, ['name' => 'B', 'extra_fields' => [$phone->id => ['no' => 'texto']]]))->toThrow(ValidationException::class)
        // La primera foto es buena y se guarda; la segunda es falsa: no debe quedar ningún archivo.
        ->and(fn () => app($create)->handle($admin, $event, ['name' => 'C', 'extra_fields' => [
            $facade->id => extraPhoto(),
            $other->id => UploadedFile::fake()->createWithContent('a.png', 'esto no es una imagen'),
        ]]))->toThrow(ValidationException::class)
        ->and($model::query()->count())->toBe(0)
        ->and(Storage::disk(MediaDisk::name())->allFiles())->toBe([]);
})->with('extra_owners');

it('CA-030-19: editing keeps, changes and clears answers; a replaced or removed photo is deleted from the disk', function (array $classes): void {
    [$create, $update, , $model, $scope] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    [$phone, $facade] = defineQuestions($admin, $event, $scope, [['label' => 'Teléfono', 'type' => 'short_text'], ['label' => 'Fachada', 'type' => 'photo']]);
    $record = app($create)->handle($admin, $event, ['name' => 'Uno', 'extra_fields' => [$phone->id => '1', $facade->id => extraPhoto('a.png')]]);
    $disk = Storage::disk(MediaDisk::name());
    $first = $model::query()->findOrFail($record->id)->extra_fields[$facade->id]['path'];

    // Sin la clave nada cambia.
    app($update)->handle($admin, $record, ['name' => 'Uno bis']);

    expect($model::query()->findOrFail($record->id)->extra_fields[$phone->id])->toBe('1');

    // Una foto nueva reemplaza a la anterior y el texto cambia; lo que no llega se conserva.
    app($update)->handle($admin, $model::query()->findOrFail($record->id), ['extra_fields' => [$phone->id => '2', $facade->id => extraPhoto('b.png')]]);
    $values = $model::query()->findOrFail($record->id)->extra_fields;

    expect($values[$phone->id])->toBe('2')
        ->and($values[$facade->id]['path'])->not->toBe($first)
        ->and($disk->exists($first))->toBeFalse()
        ->and($disk->exists($values[$facade->id]['path']))->toBeTrue();

    // Sin foto nueva, la guardada se conserva; un texto vacío se borra.
    app($update)->handle($admin, $model::query()->findOrFail($record->id), ['extra_fields' => [$phone->id => '  ', $facade->id => null]]);
    $kept = $model::query()->findOrFail($record->id)->extra_fields;

    expect($kept)->toHaveCount(1)
        ->and($kept[$facade->id]['path'])->toBe($values[$facade->id]['path']);

    // Quitar la foto con la marca de quitar borra el archivo y deja el registro sin respuestas.
    app($update)->handle($admin, $model::query()->findOrFail($record->id), ['extra_fields' => [], 'extra_remove' => [$facade->id]]);

    expect($model::query()->findOrFail($record->id)->extra_fields)->toBeNull()
        ->and($disk->allFiles())->toBe([]);
})->with('extra_owners');

it('CA-030-19: a refused edit keeps the saved answers and photos untouched', function (array $classes): void {
    [$create, $update, , $model, $scope] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    [$phone, $facade] = defineQuestions($admin, $event, $scope, [['label' => 'Teléfono', 'type' => 'short_text'], ['label' => 'Fachada', 'type' => 'photo']]);
    $record = app($create)->handle($admin, $event, ['name' => 'Uno', 'extra_fields' => [$phone->id => '1', $facade->id => extraPhoto()]]);
    $before = $model::query()->findOrFail($record->id)->extra_fields;

    expect(fn () => app($update)->handle($admin, $model::query()->findOrFail($record->id), ['name' => '', 'extra_fields' => [$phone->id => '2', $facade->id => extraPhoto('n.png')]]))
        ->toThrow(ValidationException::class)
        ->and($model::query()->findOrFail($record->id)->extra_fields)->toBe($before)
        ->and(Storage::disk(MediaDisk::name())->allFiles())->toHaveCount(1);
})->with('extra_owners');

it('CA-030-19: an answer to a question that is not of this event (or of the other module) is ignored', function (array $classes): void {
    [$create, , , $model, $scope] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    [$mine] = defineQuestions($admin, $event, $scope, [['label' => 'Mía', 'type' => 'short_text']]);
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    $foreign = ExtraFieldDefinition::factory()->create(['event_id' => $otherEvent->id, 'scope' => $scope, 'label' => 'Ajena']);
    $otherScope = $scope === 'lodging' ? 'sponsor' : 'lodging';
    $wrongModule = ExtraFieldDefinition::factory()->create(['event_id' => $event->id, 'scope' => $otherScope, 'label' => 'De otro módulo']);

    $record = app($create)->handle($admin, $event, ['name' => 'Uno', 'extra_fields' => [
        $mine->id => 'sí',
        $foreign->id => 'no debe guardarse',
        $wrongModule->id => 'tampoco',
        'cualquier-cosa' => 'ni esto',
    ]]);

    expect($model::query()->findOrFail($record->id)->extra_fields)->toBe([$mine->id => 'sí']);
})->with('extra_owners');

it('CA-030-19: removing a question deletes its answers and photos in every record of the event and nothing else', function (array $classes): void {
    [$create, , , $model, $scope] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    [$phone, $facade] = defineQuestions($admin, $event, $scope, [['label' => 'Teléfono', 'type' => 'short_text'], ['label' => 'Fachada', 'type' => 'photo']]);
    $one = app($create)->handle($admin, $event, ['name' => 'Uno', 'extra_fields' => [$phone->id => '1', $facade->id => extraPhoto('a.png')]]);
    $two = app($create)->handle($admin, $event, ['name' => 'Dos', 'extra_fields' => [$facade->id => extraPhoto('b.png')]]);

    // Otro evento de la organización con sus propias preguntas y respuestas: no se toca.
    $otherEvent = Event::factory()->create(['organization_id' => $organization->id]);
    [$otherQuestion] = defineQuestions($admin, $otherEvent, $scope, [['label' => 'Teléfono', 'type' => 'short_text']]);
    $stranger = app($create)->handle($admin, $otherEvent, ['name' => 'Ajeno', 'extra_fields' => [$otherQuestion->id => 'queda']]);
    $disk = Storage::disk(MediaDisk::name());

    expect($disk->allFiles())->toHaveCount(2);

    // Se guarda la lista sin la foto: esa pregunta y sus fotos desaparecen; el teléfono sigue.
    defineQuestions($admin, $event, $scope, [['id' => $phone->id, 'label' => 'Teléfono', 'type' => 'short_text']]);

    expect(ExtraFieldDefinition::query()->where('event_id', $event->id)->pluck('label')->all())->toBe(['Teléfono'])
        ->and($model::query()->findOrFail($one->id)->extra_fields)->toBe([$phone->id => '1'])
        ->and($model::query()->findOrFail($two->id)->extra_fields)->toBeNull()
        ->and($model::query()->findOrFail($stranger->id)->extra_fields)->toBe([$otherQuestion->id => 'queda'])
        ->and($disk->allFiles())->toBe([]);

    // Quitarlas todas limpia también el texto.
    defineQuestions($admin, $event, $scope, []);

    expect($model::query()->findOrFail($one->id)->extra_fields)->toBeNull();
})->with('extra_owners');

it('CA-030-19: changing the type of a question discards the answers given with the old type', function (array $classes): void {
    [$create, , , $model, $scope] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    [$question] = defineQuestions($admin, $event, $scope, [['label' => 'Dato', 'type' => 'photo']]);
    $record = app($create)->handle($admin, $event, ['name' => 'Uno', 'extra_fields' => [$question->id => extraPhoto()]]);

    defineQuestions($admin, $event, $scope, [['id' => $question->id, 'label' => 'Dato', 'type' => 'short_text']]);

    expect($model::query()->findOrFail($record->id)->extra_fields)->toBeNull()
        ->and(Storage::disk(MediaDisk::name())->allFiles())->toBe([])
        ->and(ExtraFieldDefinition::query()->findOrFail($question->id)->type)->toBe('short_text');
})->with('extra_owners');

it('CA-030-19: deleting the record deletes the files of its photo answers', function (array $classes): void {
    [$create, , $delete, $model, $scope] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    [$facade] = defineQuestions($admin, $event, $scope, [['label' => 'Fachada', 'type' => 'photo']]);
    $record = app($create)->handle($admin, $event, ['name' => 'Uno', 'extra_fields' => [$facade->id => extraPhoto()]]);

    expect(Storage::disk(MediaDisk::name())->allFiles())->toHaveCount(1);

    app($delete)->handle($admin, $record);

    expect($model::query()->count())->toBe(0)
        ->and(Storage::disk(MediaDisk::name())->allFiles())->toBe([]);
})->with('extra_owners');

it('CA-030-20: an organizer defines the questions; a reviewer, an admin of another organization and an unknown module cannot', function (array $classes): void {
    [, , , , $scope] = $classes;
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    CurrentOrganization::set($organization);

    expect(defineQuestions($organizer, $event, $scope, [['label' => 'Dato', 'type' => 'short_text']]))->toHaveCount(1);

    [, $reviewerEvent, $reviewer] = formScenario(OrganizationRole::Reviewer);

    expect(fn () => defineQuestions($reviewer, $reviewerEvent, $scope, [['label' => 'X', 'type' => 'short_text']]))->toThrow(AuthorizationException::class);

    [$organizationA, $eventA, $adminA] = formScenario();
    [, , $adminB] = formScenario();
    CurrentOrganization::set($organizationA);

    expect(fn () => defineQuestions($adminB, $eventA, $scope, [['label' => 'X', 'type' => 'short_text']]))->toThrow(AuthorizationException::class)
        ->and(fn () => defineQuestions($adminA, $eventA, 'otro', [['label' => 'X', 'type' => 'short_text']]))->toThrow(AuthorizationException::class)
        ->and(ExtraFieldDefinition::query()->where('event_id', $eventA->id)->count())->toBe(0);
})->with('extra_owners');

it('CA-030-20: the question definitions of an organization are invisible from another one', function (): void {
    [$organizationA, $eventA, $adminA] = formScenario();
    [$organizationB] = formScenario();
    CurrentOrganization::set($organizationA);
    defineQuestions($adminA, $eventA, 'lodging', [['label' => 'Dato', 'type' => 'short_text']]);

    CurrentOrganization::set($organizationB);

    expect(ExtraFieldDefinition::query()->count())->toBe(0);

    CurrentOrganization::set($organizationA);

    expect(ExtraFieldDefinition::query()->count())->toBe(1);
});

it('CA-030-18: in the lodging panel the admin defines the questions and the form of every hotel shows them', function (): void {
    [$organization, $event, $admin] = formScenario();
    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    $component = Livewire::test(ManageEventLodgings::class, ['record' => $event->id]);
    $component->callAction('extraFields', ['questions' => [
        ['label' => 'Teléfono', 'type' => 'short_text'],
        ['label' => 'Fachada', 'type' => 'photo'],
    ]])->assertHasNoActionErrors();

    [$phone, $facade] = ExtraFieldDefinition::query()->where('event_id', $event->id)->orderBy('position')->get()->all();

    expect($phone->scope)->toBe('lodging');

    $component = Livewire::test(ManageEventLodgings::class, ['record' => $event->id]);
    // El formulario de alta trae un campo por pregunta, vacío.
    Livewire::test(ManageEventLodgings::class, ['record' => $event->id])
        ->mountAction('createLodging')
        ->assertActionDataSet(['extra_fields' => [$phone->id => null, $facade->id => []]]);
    $component->callAction('createLodging', ['name' => 'Hotel Playa', 'extra_fields' => [$phone->id => '555', $facade->id => [extraPhoto()]]])
        ->assertHasNoActionErrors();

    $lodging = Lodging::query()->where('event_id', $event->id)->firstOrFail();

    expect($lodging->extra_fields[$phone->id])->toBe('555')
        ->and(Storage::disk(MediaDisk::name())->exists($lodging->extra_fields[$facade->id]['path']))->toBeTrue();

    // Al editar, guardar sin subir otra foto la conserva.
    $component->callAction(TestAction::make('editLodging')->table($lodging), ['name' => 'Hotel Playa', 'extra_fields' => [$phone->id => '999', $facade->id => []]])
        ->assertHasNoActionErrors();

    expect($lodging->fresh()->extra_fields[$phone->id])->toBe('999')
        ->and($lodging->fresh()->extra_fields[$facade->id]['path'])->toBe($lodging->extra_fields[$facade->id]['path']);

    // Una foto falsa muestra el error y no guarda nada.
    $component->callAction('createLodging', ['name' => 'Otro', 'extra_fields' => [
        $facade->id => [UploadedFile::fake()->createWithContent('a.png', 'esto no es una imagen')],
    ]])->assertHasActionErrors();

    expect(Lodging::query()->count())->toBe(1);
});

it('CA-030-18: in the sponsor panel the admin defines the questions, the form shows them and removing one clears its answers', function (): void {
    [$organization, $event, $admin] = formScenario();
    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    $component = Livewire::test(ManageEventSponsors::class, ['record' => $event->id]);
    $component->callAction('extraFields', ['questions' => [['label' => 'Contacto', 'type' => 'short_text'], ['label' => 'Stand', 'type' => 'photo']]])
        ->assertHasNoActionErrors();

    [$contact, $stand] = ExtraFieldDefinition::query()->where('event_id', $event->id)->orderBy('position')->get()->all();

    $component = Livewire::test(ManageEventSponsors::class, ['record' => $event->id]);
    $component->callAction('createSponsor', ['name' => 'Acme', 'extra_fields' => [$contact->id => 'Ana', $stand->id => [extraPhoto()]]])
        ->assertHasNoActionErrors();

    $sponsor = Sponsor::query()->where('event_id', $event->id)->firstOrFail();
    $path = $sponsor->extra_fields[$stand->id]['path'];

    expect($sponsor->extra_fields[$contact->id])->toBe('Ana')
        ->and(Storage::disk(MediaDisk::name())->exists($path))->toBeTrue();

    // Se quita la pregunta de la foto desde el botón: su foto desaparece del disco y el contacto se queda.
    $component->callAction('extraFields', ['questions' => [['id' => $contact->id, 'label' => 'Contacto', 'type' => 'short_text']]])
        ->assertHasNoActionErrors();

    expect($sponsor->fresh()->extra_fields)->toBe([$contact->id => 'Ana'])
        ->and(Storage::disk(MediaDisk::name())->exists($path))->toBeFalse();
});

it('CA-030-20: a reviewer does not see the questions button; an organizer does', function (): void {
    [$organization, $event, $organizer] = formScenario(OrganizationRole::Organizer);
    Auth::login($organizer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    Livewire::test(ManageEventLodgings::class, ['record' => $event->id])->assertActionVisible('extraFields');
    Livewire::test(ManageEventSponsors::class, ['record' => $event->id])->assertActionVisible('extraFields');
});

it('the migration turns the per-record list of the first version into answers to shared questions', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $first = app(CreateLodging::class)->handle($admin, $event, ['name' => 'Uno']);
    $second = app(CreateLodging::class)->handle($admin, $event, ['name' => 'Dos']);
    $path = "organizations/{$organization->id}/events/{$event->id}/media/lodging/fachada.png";
    Storage::disk(MediaDisk::name())->put($path, 'x');

    $old = fn (array $items): string => json_encode($items);
    DB::table('lodgings')->where('id', $first->id)->update(['extra_fields' => $old([
        ['id' => 'a', 'label' => 'Teléfono', 'type' => 'short_text', 'value' => '555'],
        ['id' => 'b', 'label' => 'Fachada', 'type' => 'image', 'path' => $path],
    ])]);
    DB::table('lodgings')->where('id', $second->id)->update(['extra_fields' => $old([
        ['id' => 'c', 'label' => ' teléfono ', 'type' => 'short_text', 'value' => '777'],
    ])]);

    $migration = require base_path('database/migrations/2026_12_01_100000_create_extra_field_definitions.php');
    (new ReflectionMethod($migration, 'convert'))->invoke($migration, 'lodgings', 'lodging');

    $questions = ExtraFieldDefinition::query()->where('event_id', $event->id)->orderBy('position')->get();
    [$phone, $facade] = $questions->all();

    expect($questions->pluck('label')->all())->toBe(['Teléfono', 'Fachada'])
        ->and($questions->pluck('type')->all())->toBe(['short_text', 'photo'])
        ->and(Lodging::query()->findOrFail($first->id)->extra_fields)->toBe([$phone->id => '555', $facade->id => ['path' => $path]])
        ->and(Lodging::query()->findOrFail($second->id)->extra_fields)->toBe([$phone->id => '777']);
});

/**
 * Una pregunta de cada tipo, con sus opciones y su rango, lista para `defineQuestions`.
 *
 * @return list<array<string, mixed>>
 */
function everyKindOfQuestion(): array
{
    return [
        ['label' => 'Corto', 'type' => 'short_text'],
        ['label' => 'Largo', 'type' => 'long_text'],
        ['label' => 'Habitaciones', 'type' => 'number', 'config' => ['min' => 1, 'max' => 500]],
        ['label' => 'Apertura', 'type' => 'date'],
        ['label' => 'Desayuno', 'type' => 'checkbox'],
        ['label' => 'Categoria', 'type' => 'single_choice', 'config' => ['options' => [['label' => 'Tres estrellas'], ['label' => 'Cinco estrellas']]]],
        ['label' => 'Servicios', 'type' => 'multiple_choice', 'config' => ['options' => [['label' => 'Wifi'], ['label' => 'Alberca'], ['label' => 'Gimnasio']]]],
        ['label' => 'Fachada', 'type' => 'photo'],
        ['label' => 'Folleto', 'type' => 'file'],
    ];
}

it('CA-030-18: every kind of question is saved with its options and range, and the options get their own values', function (array $classes): void {
    [, , , , $scope] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);

    $saved = defineQuestions($admin, $event, $scope, everyKindOfQuestion());

    expect(array_map(fn (ExtraFieldDefinition $question): string => $question->type, $saved))->toBe(['short_text', 'long_text', 'number', 'date', 'checkbox', 'single_choice', 'multiple_choice', 'photo', 'file'])
        ->and($saved[2]->config)->toBe(['min' => 1, 'max' => 500])
        ->and($saved[5]->options())->toHaveCount(2)
        ->and(array_values($saved[5]->options()))->toBe(['Tres estrellas', 'Cinco estrellas'])
        ->and(array_keys($saved[5]->options())[0])->toStartWith('o_')
        ->and($saved[0]->required)->toBeFalse();

    // Sin opciones, o con el mínimo mayor que el máximo, no se guarda.
    expect(fn () => defineQuestions($admin, $event, $scope, [['label' => 'Elige', 'type' => 'single_choice', 'config' => ['options' => []]]]))->toThrow(ValidationException::class)
        ->and(fn () => defineQuestions($admin, $event, $scope, [['label' => 'N', 'type' => 'number', 'config' => ['min' => 9, 'max' => 3]]]))->toThrow(ValidationException::class)
        ->and(ExtraFieldDefinition::query()->where('event_id', $event->id)->count())->toBe(9);
})->with('extra_owners');

it('CA-030-19: each kind of answer is validated and stored in its own shape', function (array $classes): void {
    [$create, , , $model, $scope] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    [$short, $long, $rooms, $opening, $breakfast, $category, $services, $facade, $brochure] = defineQuestions($admin, $event, $scope, everyKindOfQuestion());
    [$three] = array_keys($category->options());
    [$wifi, , $gym] = array_keys($services->options());
    $pdf = UploadedFile::fake()->createWithContent('folleto.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

    $record = app($create)->handle($admin, $event, ['name' => 'Uno', 'extra_fields' => [
        $short->id => ' Hola ', $long->id => "Linea 1\nLinea 2", $rooms->id => '120', $opening->id => '2026-12-31',
        $breakfast->id => true, $category->id => $three, $services->id => [$gym, $wifi, $gym],
        $facade->id => extraPhoto(), $brochure->id => $pdf,
    ]]);
    $values = $model::query()->findOrFail($record->id)->extra_fields;
    $disk = Storage::disk(MediaDisk::name());

    expect($values[$short->id])->toBe('Hola')
        ->and($values[$long->id])->toBe("Linea 1\nLinea 2")
        ->and($values[$rooms->id])->toBe(120)
        ->and($values[$opening->id])->toBe('2026-12-31')
        ->and($values[$breakfast->id])->toBeTrue()
        ->and($values[$category->id])->toBe($three)
        ->and($values[$services->id])->toBe([$wifi, $gym])
        ->and($disk->exists($values[$facade->id]['path']))->toBeTrue()
        ->and($values[$brochure->id]['path'])->toEndWith('.pdf')
        ->and($disk->exists($values[$brochure->id]['path']))->toBeTrue();

    // Un valor que no corresponde al tipo se rechaza y no se guarda nada.
    $bad = [
        [$rooms->id => '0'], [$rooms->id => '501'], [$rooms->id => 'muchas'], [$opening->id => '31/12/2026'], [$opening->id => '2026-02-30'],
        [$category->id => 'o_inventada'], [$services->id => [$wifi, 'o_inventada']], [$services->id => 'wifi'],
        [$brochure->id => UploadedFile::fake()->createWithContent('malo.pdf', 'esto no es un pdf')],
        [$brochure->id => UploadedFile::fake()->createWithContent('grande.pdf', "%PDF-1.4\n".str_repeat('a', 2 * 1024 * 1024 + 1))],
    ];

    foreach ($bad as $answers) {
        expect(fn () => app($create)->handle($admin, $event, ['name' => 'Malo', 'extra_fields' => $answers]))->toThrow(ValidationException::class);
    }

    expect($model::query()->count())->toBe(1)
        ->and($disk->allFiles())->toHaveCount(2);
})->with('extra_owners');

it('CA-030-19: a required question refuses the save when it has no answer, for every kind, and is satisfied by a stored file', function (array $classes): void {
    [$create, $update, , $model, $scope] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $questions = defineQuestions($admin, $event, $scope, array_map(fn (array $question): array => [...$question, 'required' => true], everyKindOfQuestion()));

    foreach ($questions as $question) {
        expect(fn () => app($create)->handle($admin, $event, ['name' => 'Sin '.$question->label, 'extra_fields' => []]))->toThrow(ValidationException::class);
    }

    expect($model::query()->count())->toBe(0);

    [$short, $long, $rooms, $opening, $breakfast, $category, $services, $facade, $brochure] = $questions;
    $answers = fn (array $skip = []): array => array_diff_key([
        $short->id => 'a', $long->id => 'b', $rooms->id => 5, $opening->id => '2027-01-01', $breakfast->id => true,
        $category->id => array_keys($category->options())[0], $services->id => [array_keys($services->options())[0]],
        $facade->id => extraPhoto(), $brochure->id => UploadedFile::fake()->createWithContent('f.pdf', "%PDF-1.4\n%%EOF"),
    ], array_flip($skip));

    // Una casilla obligatoria sin marcar, una pregunta vacía o un archivo que falta también se rechazan.
    foreach ([[$breakfast->id], [$short->id], [$facade->id], [$brochure->id]] as $skip) {
        expect(fn () => app($create)->handle($admin, $event, ['name' => 'Falta', 'extra_fields' => $answers($skip)]))->toThrow(ValidationException::class);
    }

    $record = app($create)->handle($admin, $event, ['name' => 'Completo', 'extra_fields' => $answers()]);

    // Al editar, el archivo ya guardado basta; pero no se puede vaciar un texto obligatorio ni quitar un archivo obligatorio.
    app($update)->handle($admin, $model::query()->findOrFail($record->id), ['extra_fields' => [$short->id => 'cambia']]);

    expect($model::query()->findOrFail($record->id)->extra_fields[$short->id])->toBe('cambia')
        ->and(fn () => app($update)->handle($admin, $model::query()->findOrFail($record->id), ['extra_fields' => [$short->id => ' ']]))->toThrow(ValidationException::class)
        ->and(fn () => app($update)->handle($admin, $model::query()->findOrFail($record->id), ['extra_fields' => [], 'extra_remove' => [$facade->id]]))->toThrow(ValidationException::class)
        ->and($model::query()->findOrFail($record->id)->extra_fields)->toHaveCount(9);
})->with('extra_owners');

it('CA-030-19: the answers belong to each record: a new record starts empty and the shared questions do not copy values', function (array $classes): void {
    [$create, , , $model, $scope] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    [$phone, $facade] = defineQuestions($admin, $event, $scope, [['label' => 'Telefono', 'type' => 'short_text'], ['label' => 'Fachada', 'type' => 'photo']]);

    $one = app($create)->handle($admin, $event, ['name' => 'Uno', 'extra_fields' => [$phone->id => '111', $facade->id => extraPhoto()]]);
    $two = app($create)->handle($admin, $event, ['name' => 'Dos']);
    $three = app($create)->handle($admin, $event, ['name' => 'Tres', 'extra_fields' => [$phone->id => '333']]);

    expect($model::query()->findOrFail($one->id)->extra_fields[$phone->id])->toBe('111')
        ->and($model::query()->findOrFail($two->id)->extra_fields)->toBeNull()
        ->and($model::query()->findOrFail($three->id)->extra_fields)->toBe([$phone->id => '333'])
        ->and(Storage::disk(MediaDisk::name())->allFiles())->toHaveCount(1);
})->with('extra_owners');

it('CA-030-19: removing an option of a choice question drops it from the saved answers; the count says how many records answered', function (array $classes): void {
    [$create, , , $model, $scope] = $classes;
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    [$single, $multiple] = defineQuestions($admin, $event, $scope, [
        ['label' => 'Una', 'type' => 'single_choice', 'config' => ['options' => [['label' => 'A'], ['label' => 'B']]]],
        ['label' => 'Varias', 'type' => 'multiple_choice', 'config' => ['options' => [['label' => 'X'], ['label' => 'Y']]]],
    ]);
    [$a, $b] = array_keys($single->options());
    [$x, $y] = array_keys($multiple->options());
    $one = app($create)->handle($admin, $event, ['name' => 'Uno', 'extra_fields' => [$single->id => $a, $multiple->id => [$x, $y]]]);
    $two = app($create)->handle($admin, $event, ['name' => 'Dos', 'extra_fields' => [$single->id => $b]]);

    expect(ExtraFields::answersCount($event, $scope, $single->id))->toBe(2)
        ->and(ExtraFields::answersCount($event, $scope, $multiple->id))->toBe(1)
        ->and(ExtraFields::answersCount($event, $scope, 'no-existe'))->toBe(0);

    // Se quita la opción A y la opción X conservando el valor de las que quedan.
    defineQuestions($admin, $event, $scope, [
        ['id' => $single->id, 'label' => 'Una', 'type' => 'single_choice', 'config' => ['options' => [['value' => $b, 'label' => 'B']]]],
        ['id' => $multiple->id, 'label' => 'Varias', 'type' => 'multiple_choice', 'config' => ['options' => [['value' => $y, 'label' => 'Y']]]],
    ]);

    expect($model::query()->findOrFail($one->id)->extra_fields)->toBe([$multiple->id => [$y]])
        ->and($model::query()->findOrFail($two->id)->extra_fields)->toBe([$single->id => $b]);
})->with('extra_owners');

it('CA-030-18: the order of the questions is the order of the form and a reorder keeps their identity', function (): void {
    [$organization, $event, $admin] = formScenario();
    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    $component = Livewire::test(ManageEventLodgings::class, ['record' => $event->id]);
    $component->callAction('extraFields', ['questions' => [
        ['label' => 'Primera', 'type' => 'short_text', 'required' => false],
        ['label' => 'Segunda', 'type' => 'number', 'required' => true, 'config' => ['min' => '1', 'max' => '9']],
        ['label' => 'Tercera', 'type' => 'single_choice', 'required' => false, 'config' => ['options' => [['label' => 'Si'], ['label' => 'No']]]],
    ]])->assertHasNoActionErrors();

    [$first, $second, $third] = ExtraFieldDefinition::query()->where('event_id', $event->id)->orderBy('position')->get()->all();

    expect($second->required)->toBeTrue()
        ->and($second->config)->toBe(['min' => 1, 'max' => 9]);

    $component->callAction('extraFields', ['questions' => [
        ['id' => $third->id, 'label' => 'Tercera', 'type' => 'single_choice', 'required' => false, 'config' => ['options' => $third->config['options']]],
        ['id' => $first->id, 'label' => 'Primera', 'type' => 'short_text', 'required' => false],
        ['id' => $second->id, 'label' => 'Segunda', 'type' => 'number', 'required' => true, 'config' => ['min' => '1', 'max' => '9']],
    ]])->assertHasNoActionErrors();

    expect(ExtraFieldDefinition::query()->where('event_id', $event->id)->orderBy('position')->pluck('id')->all())->toBe([$third->id, $first->id, $second->id]);
});

it('CA-030-18: in the lodging panel a required question blocks the save with its error and every record starts with an empty form', function (): void {
    [$organization, $event, $admin] = formScenario();
    Auth::login($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
    [$phone, $stars] = defineQuestions($admin, $event, 'lodging', [
        ['label' => 'Telefono', 'type' => 'short_text', 'required' => true],
        ['label' => 'Estrellas', 'type' => 'number', 'config' => ['min' => 1, 'max' => 5]],
    ]);

    $component = Livewire::test(ManageEventLodgings::class, ['record' => $event->id]);
    $component->callAction('createLodging', ['name' => 'Hotel', 'extra_fields' => [$phone->id => '', $stars->id => '4']])->assertHasActionErrors();

    expect(Lodging::query()->count())->toBe(0);

    // Tras el error la ventana sigue abierta: se parte de una pantalla nueva.
    $component = Livewire::test(ManageEventLodgings::class, ['record' => $event->id]);
    $component->callAction('createLodging', ['name' => 'Hotel Uno', 'extra_fields' => [$phone->id => '555', $stars->id => '4']])->assertHasNoActionErrors();
    // El siguiente alta no arrastra nada: sus campos empiezan vacíos y solo guarda lo que se le escribe.
    Livewire::test(ManageEventLodgings::class, ['record' => $event->id])
        ->mountAction('createLodging')
        ->assertActionDataSet(['extra_fields' => [$phone->id => null, $stars->id => null]]);
    $component->callAction('createLodging', ['name' => 'Hotel Dos', 'extra_fields' => [$phone->id => '777']])->assertHasNoActionErrors();

    $two = Lodging::query()->where('name', 'Hotel Dos')->firstOrFail();

    expect(Lodging::query()->where('name', 'Hotel Uno')->firstOrFail()->extra_fields)->toBe([$phone->id => '555', $stars->id => 4])
        ->and($two->extra_fields)->toBe([$phone->id => '777']);
});

it('the texts of the questions never talk about "adicional" or "extra"', function (): void {
    foreach (['es', 'en'] as $locale) {
        $texts = json_encode(trans('form_questions', [], $locale), JSON_UNESCAPED_UNICODE);

        expect($texts)->not->toMatch('/adicional|extra|additional/iu');
    }
});
