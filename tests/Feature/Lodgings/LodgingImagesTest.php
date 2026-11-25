<?php

declare(strict_types=1);

use App\Actions\Lodgings\CreateLodging;
use App\Actions\Lodgings\DeleteLodging;
use App\Actions\Lodgings\UpdateLodging;
use App\Filament\Resources\Events\Pages\ManageEventLodgings;
use App\Models\Event;
use App\Models\Lodging;
use App\Models\LodgingImage;
use App\Models\Organization;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Lodgings\LodgingRules;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/form-helpers.php';

/*
 * Plan 030, F3: la galería de imágenes de un hospedaje (hasta 10 por hotel), guardadas en el disco `media`.
 */

beforeEach(function (): void {
    Storage::fake(MediaDisk::name());
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @return list<UploadedFile> */
function lodgingPhotos(int $count, string $prefix = 'foto'): array
{
    return array_map(fn (int $i): UploadedFile => UploadedFile::fake()->image("{$prefix}{$i}.png", 30, 30), range(1, $count));
}

/** @return Testable<ManageEventLodgings> */
function lodgingImagesPage(Organization $organization, User $user, Event $event): Testable
{
    Auth::login($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);

    return Livewire::test(ManageEventLodgings::class, ['record' => $event->id]);
}

it('CA-030-14: an admin adds a lodging with several images; each goes to the media disk of the event, in order', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);

    $lodging = app(CreateLodging::class)->handle($admin, $event, ['name' => 'Hotel Playa', 'images' => lodgingPhotos(3)]);
    $images = $lodging->images()->get();
    $disk = Storage::disk(MediaDisk::name());

    expect($images)->toHaveCount(3)
        ->and($images->pluck('position')->all())->toBe([1, 2, 3])
        ->and($images->pluck('event_id')->unique()->all())->toBe([$event->id])
        ->and($images->pluck('organization_id')->unique()->all())->toBe([$organization->id]);

    foreach ($images as $image) {
        expect($image->path)->toStartWith("organizations/{$organization->id}/events/{$event->id}/media/lodging_image/")
            ->and($disk->exists($image->path))->toBeTrue()
            ->and($disk->getVisibility($image->path))->toBe('private');
    }

    $log = Activity::query()->where('event', 'lodging_created')->where('subject_id', $lodging->id)->firstOrFail();

    expect($log->properties['images'])->toBe(3);
});

it('CA-030-14: a lodging without images still works and has an empty gallery', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);

    $lodging = app(CreateLodging::class)->handle($admin, $event, ['name' => 'Hotel Centro']);

    expect($lodging->images()->count())->toBe(0)
        ->and(Storage::disk(MediaDisk::name())->allFiles())->toBe([]);
});

it('CA-030-14: more than the limit, or an image that is not one, is refused in the images field and nothing is left behind', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);

    expect(LodgingRules::MAX_IMAGES)->toBe(10)
        ->and(validationErrorKeys(fn () => app(CreateLodging::class)->handle($admin, $event, ['name' => 'Demasiadas', 'images' => lodgingPhotos(11)])))->toBe(['images'])
        ->and(validationErrorKeys(fn () => app(CreateLodging::class)->handle($admin, $event, [
            'name' => 'Falsa',
            'images' => [...lodgingPhotos(2), UploadedFile::fake()->createWithContent('a.png', 'esto no es una imagen')],
        ])))->toBe(['images']);

    // Exactamente el tope sí se acepta.
    $full = app(CreateLodging::class)->handle($admin, $event, ['name' => 'Completa', 'images' => lodgingPhotos(10)]);

    expect($full->images()->count())->toBe(10)
        ->and(Lodging::query()->pluck('name')->all())->toBe(['Completa'])
        ->and(Storage::disk(MediaDisk::name())->allFiles())->toHaveCount(10);
});

it('CA-030-14: a failed save leaves no orphan image (a bad field after valid images)', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);

    expect(fn () => app(CreateLodging::class)->handle($admin, $event, ['name' => 'X', 'booking_url' => 'javascript:alert(1)', 'images' => lodgingPhotos(2)]))
        ->toThrow(ValidationException::class);

    expect(Lodging::query()->count())->toBe(0)
        ->and(LodgingImage::query()->count())->toBe(0)
        ->and(Storage::disk(MediaDisk::name())->allFiles())->toBe([]);
});

it('CA-030-15: editing adds images at the end, removes the chosen ones and deletes their files; the others keep their order', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $lodging = app(CreateLodging::class)->handle($admin, $event, ['name' => 'Hotel', 'images' => lodgingPhotos(3)]);
    [$one, $two, $three] = $lodging->images()->get()->all();
    $disk = Storage::disk(MediaDisk::name());

    $updated = app(UpdateLodging::class)->handle($admin, $lodging, ['remove_images' => [$two->id], 'images' => lodgingPhotos(2, 'nueva')]);
    $images = $updated->images()->get();

    expect($images)->toHaveCount(4)
        ->and($images->pluck('id')->take(2)->all())->toBe([$one->id, $three->id])
        ->and($images->pluck('position')->all())->toBe([1, 3, 4, 5])
        ->and($disk->exists($two->path))->toBeFalse()
        ->and($disk->exists($one->path))->toBeTrue()
        ->and($disk->allFiles())->toHaveCount(4);

    $changes = Activity::query()->where('event', 'lodging_updated')->get()->flatMap(fn (Activity $log): array => $log->properties['changed'])->all();

    expect($changes)->toContain('images');

    // Editar otros campos no toca la galería.
    app(UpdateLodging::class)->handle($admin, $updated, ['name' => 'Hotel Renombrado']);

    expect($updated->images()->count())->toBe(4)
        ->and($disk->allFiles())->toHaveCount(4);
});

it('CA-030-15: the limit counts the images that stay plus the new ones, and a refused edit keeps every file', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $lodging = app(CreateLodging::class)->handle($admin, $event, ['name' => 'Hotel', 'images' => lodgingPhotos(9)]);
    $disk = Storage::disk(MediaDisk::name());

    expect(validationErrorKeys(fn () => app(UpdateLodging::class)->handle($admin, $lodging, ['images' => lodgingPhotos(2)])))->toBe(['images'])
        ->and(validationErrorKeys(fn () => app(UpdateLodging::class)->handle($admin, $lodging, ['images' => [UploadedFile::fake()->createWithContent('a.png', 'no es imagen')]])))->toBe(['images'])
        ->and($lodging->images()->count())->toBe(9)
        ->and($disk->allFiles())->toHaveCount(9);

    // Quitar una libera lugar para dos nuevas.
    $first = $lodging->images()->firstOrFail();
    $updated = app(UpdateLodging::class)->handle($admin, $lodging, ['remove_images' => [$first->id], 'images' => lodgingPhotos(2)]);

    expect($updated->images()->count())->toBe(10)
        ->and($disk->exists($first->path))->toBeFalse();
});

it('CA-030-15: an image of another lodging cannot be removed from this one', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $mine = app(CreateLodging::class)->handle($admin, $event, ['name' => 'Mío', 'images' => lodgingPhotos(1)]);
    $other = app(CreateLodging::class)->handle($admin, $event, ['name' => 'Otro', 'images' => lodgingPhotos(1, 'ajena')]);
    $foreign = $other->images()->firstOrFail();

    app(UpdateLodging::class)->handle($admin, $mine, ['remove_images' => [$foreign->id, 'no-existe']]);

    expect($other->images()->count())->toBe(1)
        ->and($mine->images()->count())->toBe(1)
        ->and(Storage::disk(MediaDisk::name())->exists($foreign->path))->toBeTrue();
});

it('CA-030-15: deleting the lodging deletes its image rows and its files', function (): void {
    [$organization, $event, $admin] = formScenario();
    CurrentOrganization::set($organization);
    $lodging = app(CreateLodging::class)->handle($admin, $event, ['name' => 'Hotel', 'images' => lodgingPhotos(3)]);
    $keep = app(CreateLodging::class)->handle($admin, $event, ['name' => 'Se queda', 'images' => lodgingPhotos(1, 'otra')]);

    expect(Storage::disk(MediaDisk::name())->allFiles())->toHaveCount(4);

    app(DeleteLodging::class)->handle($admin, $lodging);

    expect(LodgingImage::query()->count())->toBe(1)
        ->and(LodgingImage::query()->value('lodging_id'))->toBe($keep->id)
        ->and(Storage::disk(MediaDisk::name())->allFiles())->toHaveCount(1);
});

it('CA-030-14: in the panel the admin adds a lodging with several images, sees the thumbnail, removes one and deletes the lodging', function (): void {
    [$organization, $event, $admin] = formScenario();

    $component = lodgingImagesPage($organization, $admin, $event);

    $component->callAction('createLodging', ['name' => 'Hotel Playa', 'images' => lodgingPhotos(2)])->assertHasNoActionErrors();

    $lodging = Lodging::query()->where('event_id', $event->id)->firstOrFail();
    $images = $lodging->images()->get();

    expect($images)->toHaveCount(2);

    $component->assertCanSeeTableRecords([$lodging]);

    $component->callAction(TestAction::make('editLodging')->table($lodging), ['name' => 'Hotel Playa', 'remove_images' => [$images[0]->id]])
        ->assertHasNoActionErrors();

    expect($lodging->images()->count())->toBe(1)
        ->and(Storage::disk(MediaDisk::name())->exists($images[0]->path))->toBeFalse();

    $component->callAction(TestAction::make('deleteLodging')->table($lodging))->assertHasNoActionErrors();

    expect(Lodging::query()->count())->toBe(0)
        ->and(Storage::disk(MediaDisk::name())->allFiles())->toBe([]);
});

it('CA-030-14: in the panel a fake image shows the error and saves nothing', function (): void {
    [$organization, $event, $admin] = formScenario();

    lodgingImagesPage($organization, $admin, $event)
        ->callAction('createLodging', ['name' => 'Hotel', 'images' => [UploadedFile::fake()->createWithContent('a.png', 'esto no es una imagen')]])
        ->assertHasActionErrors(['images']);

    expect(Lodging::query()->count())->toBe(0)
        ->and(Storage::disk(MediaDisk::name())->allFiles())->toBe([]);
});
