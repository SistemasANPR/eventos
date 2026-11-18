<?php

declare(strict_types=1);

use App\Enums\SubmissionFileStatus;
use App\Exceptions\Domain\OrphanFilesPurgeFailed;
use App\Models\Organization;
use App\Models\SubmissionFile;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const PURGE_COMMAND = 'files:purge-orphans';

beforeEach(function (): void {
    Storage::fake(config('filesystems.private_uploads_disk'));
    $this->travelTo('2027-02-10 12:00:00');
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/** Crea un archivo de la organización con su contenido en el disco, subido hace `$hoursAgo` horas. */
function purgeFile(Organization $organization, bool $attached, int $hoursAgo): SubmissionFile
{
    return CurrentOrganization::run($organization, function () use ($organization, $attached, $hoursAgo): SubmissionFile {
        $factory = SubmissionFile::factory();

        if ($attached) {
            $factory = $factory->attachedTo((string) Str::uuid());
        }

        $file = $factory->create(['organization_id' => $organization->id, 'uploaded_at' => now()->subHours($hoursAgo)]);
        Storage::disk(config('filesystems.private_uploads_disk'))->put($file->storage_path, "%PDF-1.4\n");

        return $file;
    });
}

function purgeOnDisk(SubmissionFile $file): bool
{
    return Storage::disk(config('filesystems.private_uploads_disk'))->exists($file->storage_path);
}

function purgeIsDeleted(SubmissionFile $file): bool
{
    return SubmissionFile::query()->withoutGlobalScopes()->withTrashed()->whereKey($file->id)->whereNotNull('deleted_at')->exists();
}

/**
 * Sustituye el disco privado por uno cuyo `delete` falla (devuelve false) para las
 * rutas que contienen alguno de `$failingFragments`; el resto se borra de verdad.
 *
 * @param  list<string>  $failingFragments
 */
function purgeDiskFailingOn(array $failingFragments): void
{
    $real = Storage::disk(config('filesystems.private_uploads_disk'));
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->andReturnUsing(static function (string $path) use ($real, $failingFragments): bool {
        foreach ($failingFragments as $fragment) {
            if (str_contains($path, $fragment)) {
                return false;
            }
        }

        return $real->delete($path);
    });

    Storage::set((string) config('filesystems.private_uploads_disk'), $disk);
}

it('CA-007-25: deletes pending files older than 24 hours and never touches attached ones', function (): void {
    $organization = Organization::factory()->create();
    $expired = purgeFile($organization, attached: false, hoursAgo: 25);
    $recent = purgeFile($organization, attached: false, hoursAgo: 23);
    $oldAttached = purgeFile($organization, attached: true, hoursAgo: 24 * 90);

    $this->artisan(PURGE_COMMAND)->assertExitCode(0);

    expect(purgeOnDisk($expired))->toBeFalse();
    expect(purgeIsDeleted($expired))->toBeTrue();
    expect(purgeOnDisk($recent))->toBeTrue();
    expect(purgeIsDeleted($recent))->toBeFalse();
    expect(purgeOnDisk($oldAttached))->toBeTrue();
    expect(purgeIsDeleted($oldAttached))->toBeFalse();
    expect($oldAttached->fresh()?->status)->toBe(SubmissionFileStatus::Attached);
});

it('CA-007-26: a second run is idempotent and a file already missing from the disk counts as deleted', function (): void {
    $organization = Organization::factory()->create();
    $present = purgeFile($organization, attached: false, hoursAgo: 30);
    $missing = purgeFile($organization, attached: false, hoursAgo: 30);
    Storage::disk(config('filesystems.private_uploads_disk'))->delete($missing->storage_path);

    $this->artisan(PURGE_COMMAND)->assertExitCode(0);
    $this->artisan(PURGE_COMMAND)->assertExitCode(0);

    expect(purgeIsDeleted($present))->toBeTrue();
    expect(purgeIsDeleted($missing))->toBeTrue();
    expect(Activity::query()->where('event', 'submission_files_purged')->count())->toBe(1);
});

it('CA-007-26: fails visibly when the disk does not respond instead of reporting zero', function (): void {
    Exceptions::fake();
    $organization = Organization::factory()->create();
    $file = purgeFile($organization, attached: false, hoursAgo: 48);
    purgeDiskFailingOn(['organizations/']);

    $this->artisan(PURGE_COMMAND)->assertExitCode(1);

    Exceptions::assertReported(OrphanFilesPurgeFailed::class);
    expect(purgeIsDeleted($file))->toBeFalse();
    expect(Activity::query()->where('event', 'submission_files_purged')->count())->toBe(0);

    // Al volver el disco, el siguiente intento lo limpia.
    Storage::set((string) config('filesystems.private_uploads_disk'), Storage::fake('purge-retry'));
    $this->artisan(PURGE_COMMAND)->assertExitCode(0);
    expect(purgeIsDeleted($file))->toBeTrue();
});

it('CA-007-27: sweeps every organization without context and records the count per organization', function (): void {
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    $filesA = [purgeFile($a, false, 30), purgeFile($a, false, 40)];
    $filesB = [purgeFile($b, false, 30)];
    expect(CurrentOrganization::has())->toBeFalse();

    $this->artisan(PURGE_COMMAND)->assertExitCode(0);

    foreach ([...$filesA, ...$filesB] as $file) {
        expect(purgeIsDeleted($file))->toBeTrue();
    }

    $counts = Activity::query()->where('event', 'submission_files_purged')->get()
        ->mapWithKeys(static fn (Activity $activity): array => [(string) $activity->properties->get('organization_id') => $activity->properties->get('count')]);
    expect($counts->all())->toBe([$a->id => 2, $b->id => 1]);

    $logged = (string) json_encode(Activity::query()->where('event', 'submission_files_purged')->get()->toArray());
    expect(str_contains($logged, 'presentacion.pdf'))->toBeFalse();
    expect(CurrentOrganization::has())->toBeFalse();
});

it('CA-007-28: an error cleaning organization A is reported and organization B is still cleaned', function (): void {
    Exceptions::fake();
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    $fileA = purgeFile($a, false, 30);
    $fileB = purgeFile($b, false, 30);
    purgeDiskFailingOn(["organizations/{$a->id}/"]);

    $this->artisan(PURGE_COMMAND)->assertExitCode(1);

    Exceptions::assertReported(OrphanFilesPurgeFailed::class);
    expect(purgeIsDeleted($fileA))->toBeFalse();
    expect(purgeIsDeleted($fileB))->toBeTrue();
});

it('CA-007-25: the command is scheduled daily without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(static fn ($scheduled): bool => str_contains((string) $scheduled->command, PURGE_COMMAND));

    expect($event === null)->toBeFalse();
    expect($event->expression)->toBe('0 3 * * *');
    expect($event->withoutOverlapping)->toBeTrue();
});
