<?php

declare(strict_types=1);

use App\Actions\Program\DeleteProgramImage;
use App\Actions\Program\StoreProgramImage;
use App\Enums\ProgramImageType;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Files\MediaDisk;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake(MediaDisk::name());
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

function programEvent(): Event
{
    $organization = Organization::factory()->create();

    return Event::factory()->create(['organization_id' => $organization->id]);
}

/** PNG de 1x1 píxel, válido. */
function validPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
}

/** WebP de 1x1 píxel, válido. */
function validWebp(): string
{
    return (string) base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', true);
}

function validJpeg(): string
{
    ob_start();
    imagejpeg(imagecreatetruecolor(2, 2));

    return (string) ob_get_clean();
}

function upload(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

/** @return list<string> */
function storedMedia(): array
{
    return Storage::disk(MediaDisk::name())->allFiles();
}

it('CA-021-3: stores JPEG, PNG and WebP by content under organization and event with a generated name', function (string $content, string $extension): void {
    $event = programEvent();

    // El nombre del cliente miente: lo que manda es el contenido.
    $path = app(StoreProgramImage::class)->handle(upload('mi foto.txt', $content), $event, ProgramImageType::Photo);

    expect($path)->toStartWith("organizations/{$event->organization_id}/events/{$event->id}/media/photo/")
        ->and($path)->toMatch('/\/[0-9a-f-]{36}\.'.$extension.'\z/')
        ->and(Storage::disk(MediaDisk::name())->exists($path))->toBeTrue()
        ->and(storedMedia())->toHaveCount(1);
})->with([
    'jpeg' => fn (): array => [validJpeg(), 'jpg'],
    'png' => fn (): array => [validPng(), 'png'],
    'webp' => fn (): array => [validWebp(), 'webp'],
]);

it('CA-021-3: rejects files that are not JPEG, PNG or WebP by content and stores nothing', function (string $name, string $content): void {
    $event = programEvent();

    expect(fn () => app(StoreProgramImage::class)->handle(upload($name, $content), $event, ProgramImageType::Photo))
        ->toThrow(ValidationException::class)
        ->and(storedMedia())->toBe([]);
})->with([
    'svg' => ['logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'html with image name' => ['foto.jpg', '<html><body><script>alert(1)</script></body></html>'],
    'gif' => ['foto.png', 'GIF89a'."\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;"],
    'pdf' => ['foto.jpg', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n"],
    'png header with garbage' => ['foto.png', "\x89PNG\r\n\x1a\n".str_repeat('x', 200)],
    'empty' => ['foto.png', ''],
]);

it('CA-021-3: rejects a polyglot image that carries PHP code', function (): void {
    $event = programEvent();

    expect(fn () => app(StoreProgramImage::class)->handle(upload('foto.png', validPng().'<?php system($_GET["c"]); ?>'), $event, ProgramImageType::Photo))
        ->toThrow(ValidationException::class)
        ->and(storedMedia())->toBe([]);
});

it('CA-021-3: enforces 5 MB for photo and image and 256 KB for icon', function (): void {
    $event = programEvent();
    $action = app(StoreProgramImage::class);
    $padded = fn (int $bytes): UploadedFile => upload('foto.png', validPng().str_repeat("\0", $bytes - strlen(validPng())));

    // Justo en el límite se acepta; un byte más, no.
    expect($action->handle($padded(5 * 1024 * 1024), $event, ProgramImageType::Photo))->toBeString()
        ->and($action->handle($padded(5 * 1024 * 1024), $event, ProgramImageType::Image))->toBeString()
        ->and($action->handle($padded(256 * 1024), $event, ProgramImageType::Icon))->toBeString();

    $stored = count(storedMedia());

    foreach ([[5 * 1024 * 1024 + 1, ProgramImageType::Photo], [5 * 1024 * 1024 + 1, ProgramImageType::Image], [256 * 1024 + 1, ProgramImageType::Icon]] as [$bytes, $type]) {
        expect(fn () => $action->handle($padded($bytes), $event, $type))->toThrow(ValidationException::class);
    }

    expect(storedMedia())->toHaveCount($stored);
});

it('CA-021-3: the validation error is reported on the file field', function (): void {
    $event = programEvent();

    try {
        app(StoreProgramImage::class)->handle(upload('x.png', '<svg/>'), $event, ProgramImageType::Icon);
        $this->fail('Debió rechazar el SVG.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('file');
    }
});

it('CA-021-18: saves on the disk named by MEDIA_DISK without touching code', function (): void {
    config(['filesystems.media_disk' => 'media_alt']);
    config(['filesystems.disks.media_alt' => ['driver' => 'local', 'root' => storage_path('framework/testing/media_alt'), 'throw' => false]]);
    Storage::fake('media_alt');
    $event = programEvent();

    $path = app(StoreProgramImage::class)->handle(upload('a.png', validPng()), $event, ProgramImageType::Image);

    expect(MediaDisk::name())->toBe('media_alt')
        ->and(Storage::disk('media_alt')->exists($path))->toBeTrue()
        ->and(Storage::disk('media')->exists($path))->toBeFalse();
});

it('CA-021-18: the default media disk is private and local', function (): void {
    expect(config('filesystems.media_disk'))->toBe('media')
        ->and(config('filesystems.disks.media.driver'))->toBe('local')
        ->and(config('filesystems.disks.media.visibility'))->toBe('private');
});

it('CA-021-3: deletes a stored image and refuses a path of another event or organization', function (): void {
    $event = programEvent();
    $other = programEvent();
    $store = app(StoreProgramImage::class);
    $mine = $store->handle(upload('a.png', validPng()), $event, ProgramImageType::Photo);
    $theirs = $store->handle(upload('b.png', validPng()), $other, ProgramImageType::Photo);

    expect(fn () => app(DeleteProgramImage::class)->handle($event, $theirs))->toThrow(InvalidArgumentException::class)
        ->and(Storage::disk(MediaDisk::name())->exists($theirs))->toBeTrue();

    app(DeleteProgramImage::class)->handle($event, $mine);

    expect(Storage::disk(MediaDisk::name())->exists($mine))->toBeFalse()
        ->and(Storage::disk(MediaDisk::name())->exists($theirs))->toBeTrue();
});
