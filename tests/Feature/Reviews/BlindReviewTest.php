<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ReviewAssignmentStatus;
use App\Filament\Resources\Submissions\Support\SubmissionDetail;
use App\Filament\Reviewer\Resources\AssignedSubmissions\AssignedSubmissionResource;
use App\Filament\Reviewer\Resources\AssignedSubmissions\Pages\EvaluateSubmission;
use App\Filament\Reviewer\Resources\AssignedSubmissions\Pages\ListAssignedSubmissions;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\Rubric;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\SubmissionParticipant;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Memberships;
use Tests\Support\TwoFactor;

uses(RefreshDatabase::class);

/*
 * Plan 011, F5 (H-011-4): revisión ciega y nombre neutro de archivo (CA-011-18 a 20, 54, DT-29). El dato de
 * autor reconocible lleva la marca `zxqautor` (o `quillfeather`): si aparece en CUALQUIER fragmento de lo que
 * recibe el navegador (HTML, atributos o estado de Livewire), el ocultamiento no es del servidor.
 */

beforeEach(function (): void {
    $this->withoutVite();
    config(['two_factor.required' => true]);
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** Marcas de los datos de autor de `blScenario`, en minúsculas. */
const BL_MARKS = ['zxqautor', 'quillfeather'];

/**
 * Una propuesta con un autor reconocible, un campo que lo identifica, uno que no, y un archivo de cada tipo.
 *
 * @return array{organization: Organization, event: Event, reviewer: User, submission: Submission, assignment: ReviewAssignment, rubric: Rubric, identifying: SubmissionFile, plain: SubmissionFile}
 */
function blScenario(bool $blind): array
{
    $organization = Memberships::organization();
    $event = Event::factory()->create(['organization_id' => $organization->id]);
    $submission = Submission::factory()->create([
        'organization_id' => $organization->id,
        'event_id' => $event->id,
        'title' => 'Propuesta sobre agua',
        'form_snapshot' => ['fields' => [
            ['key' => 'summary', 'type' => 'long_text', 'label' => ['es' => 'Resumen'], 'identifiesAuthor' => false],
            ['key' => 'background', 'type' => 'short_text', 'label' => ['es' => 'Trayectoria'], 'identifiesAuthor' => true],
            ['key' => 'letter', 'type' => 'file', 'label' => ['es' => 'Carta'], 'identifiesAuthor' => true],
            ['key' => 'slides', 'type' => 'file', 'label' => ['es' => 'Ponencia']],
        ]],
        'answers' => ['summary' => 'Resumen visible del proyecto', 'background' => 'Zxqautor trabaja en la fundacion Quillfeather'],
    ]);
    $rubric = Rubric::factory()->published()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'submission_type_id' => $submission->submission_type_id, 'blind_review' => $blind,
    ]);
    $user = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Reviewer);
    TwoFactor::enable($user);
    EventAssignment::factory()->create(['organization_id' => $organization->id, 'event_id' => $event->id, 'user_id' => $user->id]);
    $reviewer = $user->fresh();

    CurrentOrganization::run($organization, function () use ($organization, $submission): void {
        SubmissionParticipant::factory()->create([
            'organization_id' => $organization->id,
            'submission_id' => $submission->id,
            'first_name' => 'Zxqautor',
            'last_name' => 'Quillfeather',
            'full_name' => 'Zxqautor Quillfeather',
            'email' => 'zxqautor@example.test',
            'company' => 'Zxqautor Industrias',
            'job_title' => 'Directora Zxqautor',
            'bio' => 'Biografia de Zxqautor',
        ]);
    });
    $fileOf = fn (string $field, string $name, int $minutesAgo): SubmissionFile => CurrentOrganization::run($organization, fn (): SubmissionFile => SubmissionFile::factory()->attachedTo($submission->id)->create([
        'organization_id' => $organization->id,
        'call_id' => $submission->call_for_submissions_id,
        'form_field_key' => $field,
        'original_name' => $name,
        'uploaded_at' => now()->subMinutes($minutesAgo),
    ]));
    // La carta se sube antes: es el archivo 1 de la propuesta y la ponencia el 2.
    $identifying = $fileOf('letter', 'zxqautor-carta.pdf', 10);
    $plain = $fileOf('slides', 'zxqautor-ponencia.pdf', 5);

    $assignment = ReviewAssignment::factory()->create([
        'organization_id' => $organization->id, 'event_id' => $event->id, 'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id, 'status' => ReviewAssignmentStatus::Pending,
    ]);

    return compact('organization', 'event', 'reviewer', 'submission', 'assignment', 'rubric', 'identifying', 'plain');
}

/** Lo que el navegador recibe, tal como lo vería quien lee el código fuente: sin entidades HTML ni barras de JSON. */
function blNormalized(string $payload): string
{
    return strtolower(str_replace('\\', '', html_entity_decode($payload, ENT_QUOTES | ENT_HTML5)));
}

/** @return list<string> las marcas de autor que aparecen en el contenido */
function blLeaks(string $payload): array
{
    $content = blNormalized($payload);

    return array_values(array_filter(BL_MARKS, static fn (string $mark): bool => str_contains($content, $mark)));
}

function blPanel(Organization $organization, User $user): void
{
    Auth::login($user);
    Filament::setCurrentPanel('reviewer');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
}

it('CA-011-18: with blind review the detail has no authors and no identifying fields, and the rest is shown', function (): void {
    $scenario = blScenario(true);

    $detail = CurrentOrganization::run($scenario['organization'], fn (): array => SubmissionDetail::forReviewer($scenario['submission'], $scenario['organization']));

    expect($detail['participants'])->toBe([])
        ->and($detail['blind'])->toBeTrue()
        ->and(blLeaks((string) json_encode($detail)))->toBe([]);

    $labels = array_column($detail['answers'], 'label');
    expect($labels)->toContain('Resumen')->toContain('Ponencia')
        ->and(in_array('Trayectoria', $labels, true))->toBeFalse()
        ->and(in_array('Carta', $labels, true))->toBeFalse();
});

it('CA-011-18: without blind review the authors and every field are shown (control of the leak check)', function (): void {
    $scenario = blScenario(false);

    $detail = CurrentOrganization::run($scenario['organization'], fn (): array => SubmissionDetail::forReviewer($scenario['submission'], $scenario['organization']));

    expect($detail['blind'])->toBeFalse()
        ->and($detail['participants'])->toHaveCount(1)
        ->and(array_column($detail['answers'], 'label'))->toContain('Trayectoria')->toContain('Carta')
        ->and(blLeaks((string) json_encode($detail)))->not->toBe([]);
});

it('CA-011-19: the evaluation page as the browser receives it carries no author fragment in blind mode', function (): void {
    $scenario = blScenario(true);
    $url = "/reviewer/{$scenario['organization']->slug}/assigned-submissions/{$scenario['assignment']->id}";

    // La página completa tal como la pide el navegador: HTML, atributos y la carga inicial de Livewire.
    $html = $this->actingAs($scenario['reviewer'])->get($url)->assertOk()
        ->assertSee('Resumen visible del proyecto')
        ->assertSee(__('reviewer_integrity.blind.notice'))
        ->getContent();

    expect(blLeaks((string) $html))->toBe([]);
});

it('CA-011-19: the evaluation component, its rendered HTML and its state carry no author fragment in blind mode', function (): void {
    $scenario = blScenario(true);

    // El componente de Livewire: su HTML renderizado y su estado (lo que viaja en cada petición).
    blPanel($scenario['organization'], $scenario['reviewer']);
    $page = Livewire::test(EvaluateSubmission::class, ['record' => $scenario['assignment']->id]);

    expect(blLeaks($page->html()))->toBe([])
        ->and(blLeaks((string) json_encode($page->getData())))->toBe([]);

    // Tras una interacción el estado sigue sin llevarlos.
    $page->call('saveDraft');
    expect(blLeaks($page->html()))->toBe([])
        ->and(blLeaks((string) json_encode($page->getData())))->toBe([]);
});

it('CA-011-19: the same page shows the author data without blind review, so the check is real', function (): void {
    $scenario = blScenario(false);
    $url = "/reviewer/{$scenario['organization']->slug}/assigned-submissions/{$scenario['assignment']->id}";

    $html = $this->actingAs($scenario['reviewer'])->get($url)->assertOk()->getContent();

    expect(blLeaks((string) $html))->toBe(BL_MARKS);
});

it('CA-011-54: the dashboard and the list of a blind event, as the browser receives them, carry no author name or email', function (): void {
    $scenario = blScenario(true);
    $slug = $scenario['organization']->slug;

    foreach (["/reviewer/{$slug}", "/reviewer/{$slug}/assigned-submissions"] as $url) {
        $html = $this->actingAs($scenario['reviewer'])->get($url)->assertOk()->getContent();
        expect(blLeaks((string) $html))->toBe([]);
    }
});

it('CA-011-54: the list component of a blind event carries no author in its HTML or state, and searching by author finds nothing', function (): void {
    $scenario = blScenario(true);

    blPanel($scenario['organization'], $scenario['reviewer']);
    $list = Livewire::test(ListAssignedSubmissions::class)->assertCanSeeTableRecords([$scenario['assignment']]);

    expect(blLeaks($list->html()))->toBe([])
        ->and(blLeaks((string) json_encode($list->getData())))->toBe([]);

    // Buscar por el nombre del autor no encuentra nada: la búsqueda es solo por título y no delata al autor.
    $list->searchTable('zxqautor');
    $list->assertCanNotSeeTableRecords([$scenario['assignment']]);
    $list->searchTable('zxqautor@example.test');
    $list->assertCanNotSeeTableRecords([$scenario['assignment']]);

    // La consulta del recurso no carga a los participantes: no llegan ni a memoria.
    $eager = array_keys(AssignedSubmissionResource::getEloquentQuery()->getEagerLoads());
    expect($eager)->not->toContain('submission.participants');
});

it('CA-011-18: a blind propuesta without a published rubric is shown blind too (fail closed)', function (): void {
    $scenario = blScenario(false);
    CurrentOrganization::run($scenario['organization'], fn () => Rubric::query()->whereKey($scenario['rubric']->id)->update(['status' => 'draft']));

    $detail = CurrentOrganization::run($scenario['organization'], fn (): array => SubmissionDetail::forReviewer($scenario['submission'], $scenario['organization']));

    expect($detail['blind'])->toBeTrue()->and($detail['participants'])->toBe([]);
});

it('CA-011-18: the mode comes from the latest published rubric of the type of the submission', function (): void {
    $scenario = blScenario(true);
    Rubric::factory()->published()->create([
        'organization_id' => $scenario['organization']->id, 'event_id' => $scenario['event']->id,
        'submission_type_id' => $scenario['submission']->submission_type_id, 'version' => 2, 'blind_review' => false,
    ]);

    $detail = CurrentOrganization::run($scenario['organization'], fn (): array => SubmissionDetail::forReviewer($scenario['submission'], $scenario['organization']));

    expect($detail['blind'])->toBeFalse();
});

/**
 * Pide una descarga con la URL temporal del disco privado fijada y devuelve lo que el servidor pidió como
 * `Content-Disposition`. `$get` hace la petición como el usuario que toque.
 *
 * @param  Closure(string): TestResponse<Response>  $get
 * @return array{status: int, options: array<string, mixed>}
 */
function blCaptureDisposition(Closure $get, string $path): array
{
    $captured = [];
    Storage::disk(config('filesystems.private_uploads_disk'))->buildTemporaryUrlsUsing(function (string $path, mixed $expiration, array $options) use (&$captured): string {
        $captured = $options;

        return 'https://bucket.test/'.$path.'?signature=abc';
    });

    return ['status' => $get($path)->getStatusCode(), 'options' => $captured];
}

function blFilePath(string $organizationSlug, SubmissionFile $file, string $prefix = 'reviewer'): string
{
    return "/{$prefix}/{$organizationSlug}/submission-files/{$file->id}";
}

it('CA-011-20: the reviewer downloads with the neutral name propuesta-N.ext and never the original, blind or not', function (bool $blind): void {
    $scenario = blScenario($blind);
    Storage::fake(config('filesystems.private_uploads_disk'));

    $download = blCaptureDisposition(
        fn (string $path): TestResponse => $this->actingAs($scenario['reviewer'])->get($path),
        blFilePath($scenario['organization']->slug, $scenario['plain']),
    );
    $disposition = (string) ($download['options']['ResponseContentDisposition'] ?? '');

    expect($download['status'])->toBe(302)
        ->and($disposition)->toBe('attachment; filename="propuesta-2.pdf"')
        ->and(strtolower($disposition))->not->toContain('zxqautor')
        ->and(strtolower($disposition))->not->toContain($scenario['plain']->id);
})->with([[true], [false]]);

it('CA-011-20: the organizers keep the generated uuid name, not the neutral one', function (): void {
    $scenario = blScenario(false);
    Storage::fake(config('filesystems.private_uploads_disk'));
    $admin = Memberships::attach(User::factory()->create(), $scenario['organization'], OrganizationRole::OrgAdmin);
    TwoFactor::enable($admin);

    $download = blCaptureDisposition(
        fn (string $path): TestResponse => $this->actingAs($admin->fresh())->get($path),
        blFilePath($scenario['organization']->slug, $scenario['plain'], 'admin'),
    );

    expect($download['status'])->toBe(302)
        ->and($download['options']['ResponseContentDisposition'])->toBe('attachment; filename="'.$scenario['plain']->id.'.pdf"');
});

it('CA-011-18: in blind mode the reviewer cannot download a file of an identifying field, by either route', function (): void {
    $scenario = blScenario(true);
    Storage::fake(config('filesystems.private_uploads_disk'));
    $slug = $scenario['organization']->slug;
    $asReviewer = fn (string $path): TestResponse => $this->actingAs($scenario['reviewer'])->get($path);

    expect(blCaptureDisposition($asReviewer, blFilePath($slug, $scenario['identifying']))['status'])->toBe(403)
        ->and(blCaptureDisposition($asReviewer, blFilePath($slug, $scenario['identifying'], 'admin'))['status'])->toBe(403)
        ->and(blCaptureDisposition($asReviewer, blFilePath($slug, $scenario['plain']))['status'])->toBe(302);

    // Sin modo ciego el mismo archivo sí se descarga.
    $open = blScenario(false);
    $asOpenReviewer = fn (string $path): TestResponse => $this->actingAs($open['reviewer'])->get($path);

    expect(blCaptureDisposition($asOpenReviewer, blFilePath($open['organization']->slug, $open['identifying']))['status'])->toBe(302);
});
