<?php

declare(strict_types=1);

use App\Actions\Privacy\ApplyRetentionPolicy;
use App\Actions\Privacy\SetRetentionPolicies;
use App\Enums\OrganizationRole;
use App\Enums\RetentionCategory;
use App\Enums\SubmissionStatus;
use App\Filament\Pages\Privacy\RetentionSettings;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\MailDispatch;
use App\Models\Organization;
use App\Models\RetentionPolicy;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\Submission;
use App\Models\SubmissionAccessToken;
use App\Models\SubmissionFile;
use App\Models\SubmissionParticipant;
use App\Models\User;
use App\Support\Files\PrivateUploads;
use App\Support\Privacy\RetentionReport;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Memberships;
use Tests\Support\PersonFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PersonFixtures::prepare();
});

afterEach(function (): void {
    CurrentOrganization::forget();
    Filament::setTenant(null);
});

/** @param  array<string, int>  $days  días por categoría */
function setPolicies(Organization $organization, array $days): void
{
    CurrentOrganization::run($organization, function () use ($days): void {
        foreach ($days as $category => $retention) {
            RetentionPolicy::factory()->create(['category' => $category, 'retention_days' => $retention]);
        }
    });
}

/**
 * Una propuesta con su autora, un archivo en el disco y un enlace de acceso. `$status` y los días de antigüedad
 * fijan cuándo se rechazó (o, sin cambio de estado, cuándo se envió).
 *
 * @return array{submission: Submission, participant: SubmissionParticipant, contact: Contact, file: SubmissionFile, token: SubmissionAccessToken}
 */
function submissionAged(Organization $organization, SubmissionStatus $status, int $daysAgo, string $email): array
{
    return CurrentOrganization::run($organization, function () use ($status, $daysAgo, $email): array {
        $submission = Submission::factory()->create([
            'status' => $status,
            'withdrawn_at' => $status === SubmissionStatus::Withdrawn ? now()->subDays($daysAgo) : null,
            'submitted_at' => now()->subDays($daysAgo),
            'title' => 'Charla de '.$email,
            'author_comment' => 'Hola '.$email,
        ]);
        $contact = Contact::factory()->create(['email' => $email, 'full_name' => 'Autora de '.$email]);
        $participant = SubmissionParticipant::factory()->create([
            'submission_id' => $submission->id, 'contact_id' => $contact->id, 'email' => $email, 'full_name' => 'Autora Numero '.md5($email),
        ]);
        $file = SubmissionFile::factory()->attachedTo($submission->id)->create(['call_id' => $submission->call_for_submissions_id]);
        Storage::disk((string) config('filesystems.private_uploads_disk'))->put($file->storage_path, 'x');
        $token = SubmissionAccessToken::factory()->create(['submission_id' => $submission->id]);

        return compact('submission', 'participant', 'contact', 'file', 'token');
    });
}

function retain(Organization $organization): RetentionReport
{
    return app(ApplyRetentionPolicy::class)->handle($organization);
}

/* ------------------------------------------------------------------ CA-014-33 */

it('CA-014-33: with a period for unaccepted submissions, only the expired rejected or withdrawn ones are anonymized and no other category is touched', function (): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    setPolicies($organization, [RetentionCategory::UnacceptedSubmissions->value => 100]);
    $oldRejected = submissionAged($organization, SubmissionStatus::Rejected, 150, 'vieja.rechazada@example.test');
    $oldWithdrawn = submissionAged($organization, SubmissionStatus::Withdrawn, 150, 'vieja.retirada@example.test');
    $recentRejected = submissionAged($organization, SubmissionStatus::Rejected, 10, 'reciente@example.test');
    $oldAccepted = submissionAged($organization, SubmissionStatus::Accepted, 400, 'aceptada@example.test');
    $oldInReview = submissionAged($organization, SubmissionStatus::UnderReview, 400, 'enrevision@example.test');
    // Otras categorías, sin plazo, con datos viejos: no se tocan.
    $person = PersonFixtures::person($organization);
    CurrentOrganization::run($organization, fn () => MailDispatch::factory()->create(['created_at' => now()->subDays(900)]));
    DB::table('review_answers')->where('id', $person['answer']->id)->update(['created_at' => now()->subDays(900)]);
    $before = ['mail' => DB::table('mail_dispatches')->pluck('recipient_hash')->all(), 'text' => DB::table('review_answers')->pluck('value_text')->all()];

    $report = retain($organization);

    expect($report->countOf(RetentionCategory::UnacceptedSubmissions))->toBe(2)
        ->and($report->counts)->toHaveKey('unaccepted_submissions')->and($report->counts)->not->toHaveKey('reviews')
        ->and($report->failed)->toBe([]);

    foreach ([$oldRejected, $oldWithdrawn] as $expired) {
        expect(DB::table('submission_participants')->where('id', $expired['participant']->id)->value('anonymized_at'))->not->toBeNull()
            ->and(DB::table('submission_participants')->where('id', $expired['participant']->id)->value('full_name'))->toBe('Persona anonimizada')
            ->and(DB::table('contacts')->where('id', $expired['contact']->id)->value('anonymized_at'))->not->toBeNull()
            ->and(DB::table('submission_files')->where('id', $expired['file']->id)->value('anonymized_at'))->not->toBeNull()
            ->and(Storage::disk((string) config('filesystems.private_uploads_disk'))->exists($expired['file']->storage_path))->toBeFalse()
            ->and(DB::table('submission_access_tokens')->where('id', $expired['token']->id)->value('consumed_at'))->not->toBeNull()
            ->and(DB::table('submissions')->where('id', $expired['submission']->id)->value('author_comment'))->toBe('[anonimizado]')
            // El contenido de la propuesta se conserva, sin el correo de la autora.
            ->and(DB::table('submissions')->where('id', $expired['submission']->id)->value('title'))->toBe('Charla de [anonimizado]');
    }

    foreach ([$recentRejected, $oldAccepted, $oldInReview] as $kept) {
        expect(DB::table('submission_participants')->where('id', $kept['participant']->id)->value('anonymized_at'))->toBeNull()
            ->and(DB::table('contacts')->where('id', $kept['contact']->id)->value('anonymized_at'))->toBeNull()
            ->and(Storage::disk((string) config('filesystems.private_uploads_disk'))->exists($kept['file']->storage_path))->toBeTrue();
    }

    expect(DB::table('mail_dispatches')->pluck('recipient_hash')->all())->toBe($before['mail'])
        ->and(DB::table('review_answers')->pluck('value_text')->all())->toBe($before['text']);
});

it('CA-014-33: a contact that still has an accepted submission keeps its data when an old rejected one expires', function (): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    setPolicies($organization, [RetentionCategory::UnacceptedSubmissions->value => 100]);
    $rejected = submissionAged($organization, SubmissionStatus::Rejected, 150, 'doble@example.test');
    // La misma persona, con una propuesta aceptada: su contacto no puede anonimizarse.
    $accepted = CurrentOrganization::run($organization, function () use ($rejected): SubmissionParticipant {
        $submission = Submission::factory()->create(['status' => SubmissionStatus::Accepted]);

        return SubmissionParticipant::factory()->create(['submission_id' => $submission->id, 'contact_id' => $rejected['contact']->id, 'email' => 'doble@example.test']);
    });

    retain($organization);

    expect(DB::table('submission_participants')->where('id', $rejected['participant']->id)->value('anonymized_at'))->not->toBeNull()
        ->and(DB::table('submission_participants')->where('id', $accepted->id)->value('anonymized_at'))->toBeNull()
        ->and(DB::table('contacts')->where('id', $rejected['contact']->id)->value('anonymized_at'))->toBeNull();
});

it('CA-014-33: with a period for reviews, expired comments are replaced and the scores and the recent comments stay', function (): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    setPolicies($organization, [RetentionCategory::Reviews->value => 100]);
    [$oldText, $oldScore, $recent] = CurrentOrganization::run($organization, function (): array {
        $oldReview = Review::factory()->submitted()->create(['submitted_at' => now()->subDays(200)]);
        $recentReview = Review::factory()->submitted()->create(['submitted_at' => now()->subDays(5)]);

        return [
            ReviewAnswer::factory()->text('Comentario viejo')->create(['review_id' => $oldReview->id]),
            ReviewAnswer::factory()->create(['review_id' => $oldReview->id]),
            ReviewAnswer::factory()->text('Comentario reciente')->create(['review_id' => $recentReview->id]),
        ];
    });

    $report = retain($organization);

    expect($report->countOf(RetentionCategory::Reviews))->toBe(1)
        ->and(DB::table('review_answers')->where('id', $oldText->id)->value('value_text'))->toBe('[anonimizado]')
        ->and(DB::table('review_answers')->where('id', $oldScore->id)->value('value_int'))->toBe(4)
        ->and(DB::table('review_answers')->where('id', $recent->id)->value('value_text'))->toBe('Comentario reciente');
});

it('CA-014-33: with a period for mail dispatches, only the expired ones lose their recipient link', function (): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    setPolicies($organization, [RetentionCategory::MailDispatches->value => 30]);
    [$old, $recent] = CurrentOrganization::run($organization, fn (): array => [
        MailDispatch::factory()->create(['created_at' => now()->subDays(60)]),
        MailDispatch::factory()->create(['created_at' => now()->subDays(2)]),
    ]);
    $recentHash = DB::table('mail_dispatches')->where('id', $recent->id)->value('recipient_hash');

    expect(retain($organization)->countOf(RetentionCategory::MailDispatches))->toBe(1)
        ->and(DB::table('mail_dispatches')->where('id', $old->id)->value('recipient_hash'))->toBe(hash('sha256', 'anonymized:'.$old->id))
        ->and(DB::table('mail_dispatches')->where('id', $recent->id)->value('recipient_hash'))->toBe($recentHash);
});

it('CA-014-33: without any period nothing is done and nothing is audited (deny by default)', function (): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    $aged = submissionAged($organization, SubmissionStatus::Rejected, 5000, 'sinplazo@example.test');
    CurrentOrganization::run($organization, fn () => MailDispatch::factory()->create(['created_at' => now()->subDays(5000)]));
    $snapshot = collect(['submission_participants', 'contacts', 'mail_dispatches', 'activity_log', 'submission_files'])->map(fn (string $table): string => DB::table($table)->get()->toJson())->all();

    $report = retain($organization);

    expect($report->applied())->toBeFalse()->and($report->counts)->toBe([])
        ->and(collect(['submission_participants', 'contacts', 'mail_dispatches', 'activity_log', 'submission_files'])->map(fn (string $table): string => DB::table($table)->get()->toJson())->all())->toBe($snapshot)
        ->and(Storage::disk((string) config('filesystems.private_uploads_disk'))->exists($aged['file']->storage_path))->toBeTrue();
});

it('CA-014-33: the backups category is declarative: a period for it makes the job do nothing', function (): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    setPolicies($organization, [RetentionCategory::Backups->value => 30]);
    submissionAged($organization, SubmissionStatus::Rejected, 5000, 'respaldo@example.test');

    expect(retain($organization)->applied())->toBeFalse()
        ->and(DB::table('activity_log')->where('event', 'retention_applied')->count())->toBe(0);
});

it('CA-014-33: the periods of one organization never act on another one', function (): void {
    [$organizationA] = PersonFixtures::organizationWithAdmin();
    [$organizationB] = PersonFixtures::organizationWithAdmin();
    setPolicies($organizationA, [RetentionCategory::UnacceptedSubmissions->value => 10, RetentionCategory::AuditLog->value => 10]);
    $a = submissionAged($organizationA, SubmissionStatus::Rejected, 100, 'a@example.test');
    $b = submissionAged($organizationB, SubmissionStatus::Rejected, 100, 'b@example.test');
    CurrentOrganization::run($organizationB, fn () => activity()->withProperties(['organization_id' => $organizationB->id])->log('hecho viejo de B'));
    Activity::query()->where('description', 'hecho viejo de B')->update(['created_at' => now()->subDays(500)]);

    retain($organizationA);

    expect(DB::table('submission_participants')->where('id', $a['participant']->id)->value('anonymized_at'))->not->toBeNull()
        ->and(DB::table('submission_participants')->where('id', $b['participant']->id)->value('anonymized_at'))->toBeNull()
        ->and(DB::table('activity_log')->where('description', 'hecho viejo de B')->count())->toBe(1);
});

/* ------------------------------------------------------------------ CA-014-34 */

it('CA-014-34: running it twice is idempotent: the second run changes nothing', function (): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    setPolicies($organization, [RetentionCategory::UnacceptedSubmissions->value => 100, RetentionCategory::MailDispatches->value => 30, RetentionCategory::Reviews->value => 100]);
    submissionAged($organization, SubmissionStatus::Rejected, 150, 'idem@example.test');
    CurrentOrganization::run($organization, fn () => MailDispatch::factory()->create(['created_at' => now()->subDays(60)]));

    $first = retain($organization);
    $snapshot = collect(['submission_participants', 'contacts', 'mail_dispatches', 'submission_files', 'submissions'])->map(fn (string $table): string => DB::table($table)->get()->toJson())->all();
    $second = retain($organization);

    expect($first->countOf(RetentionCategory::UnacceptedSubmissions))->toBe(1)
        ->and($second->total())->toBe(0)
        ->and(collect(['submission_participants', 'contacts', 'mail_dispatches', 'submission_files', 'submissions'])->map(fn (string $table): string => DB::table($table)->get()->toJson())->all())->toBe($snapshot);
});

it('CA-014-34: a failure halfway leaves no submission half done and the retry completes it', function (): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    setPolicies($organization, [RetentionCategory::UnacceptedSubmissions->value => 100]);
    $one = submissionAged($organization, SubmissionStatus::Rejected, 150, 'uno@example.test');
    $two = submissionAged($organization, SubmissionStatus::Rejected, 150, 'dos@example.test');

    // El disco se niega a borrar el archivo de la primera propuesta.
    $this->partialMock(PrivateUploads::class, fn ($mock) => $mock->shouldReceive('deleteConfirmed')->andReturnUsing(
        fn (string $path): bool => $path !== $one['file']->storage_path,
    ));
    $first = retain($organization);

    expect($first->failed)->toBe(['unaccepted_submissions'])
        ->and($first->countOf(RetentionCategory::UnacceptedSubmissions))->toBe(1)
        // La que falló no cambió nada: ni la autora, ni la ficha del archivo, ni el enlace.
        ->and(DB::table('submission_participants')->where('id', $one['participant']->id)->value('anonymized_at'))->toBeNull()
        ->and(DB::table('submission_files')->where('id', $one['file']->id)->value('anonymized_at'))->toBeNull()
        ->and(DB::table('submission_access_tokens')->where('id', $one['token']->id)->value('consumed_at'))->toBeNull()
        ->and(DB::table('submission_participants')->where('id', $two['participant']->id)->value('anonymized_at'))->not->toBeNull();

    // Se arregla el disco y se reintenta: termina lo pendiente sin repetir lo hecho.
    $this->app->forgetInstance(PrivateUploads::class);
    $second = retain($organization);

    expect($second->failed)->toBe([])
        ->and($second->countOf(RetentionCategory::UnacceptedSubmissions))->toBe(1)
        ->and(DB::table('submission_participants')->where('id', $one['participant']->id)->value('anonymized_at'))->not->toBeNull();
});

/* ------------------------------------------------------------------ CA-014-35 */

it('CA-014-35: a user with retention.manage sets the periods of their organization only, and the audit has the periods and no personal data', function (): void {
    [$organizationA, $adminA] = PersonFixtures::organizationWithAdmin();
    [$organizationB] = PersonFixtures::organizationWithAdmin();

    $result = CurrentOrganization::run($organizationA, fn (): array => app(SetRetentionPolicies::class)->handle($adminA, [
        'unaccepted_submissions' => '365', 'audit_log' => 730, 'reviews' => null,
    ]));

    expect($result)->toEqual(['unaccepted_submissions' => 365, 'audit_log' => 730, 'reviews' => null])
        ->and(DB::table('retention_policies')->where('organization_id', $organizationA->id)->count())->toBe(2)
        ->and(DB::table('retention_policies')->where('organization_id', $organizationB->id)->count())->toBe(0);

    $entry = Activity::query()->where('event', 'retention_policies_updated')->sole();
    expect($entry->causer_id)->toBe($adminA->id)
        ->and($entry->organization_id)->toBe($organizationA->id)
        ->and($entry->properties['policies']['unaccepted_submissions'])->toBe(365);
});

it('CA-014-35: changing and clearing a period updates or removes it, and a category left out does not change', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    setPolicies($organization, [RetentionCategory::Reviews->value => 100, RetentionCategory::AuditLog->value => 200]);

    CurrentOrganization::run($organization, fn () => app(SetRetentionPolicies::class)->handle($admin, ['reviews' => '', 'audit_log' => 300]));

    expect(DB::table('retention_policies')->where('category', 'reviews')->exists())->toBeFalse()
        ->and(DB::table('retention_policies')->where('category', 'audit_log')->value('retention_days'))->toBe(300);

    CurrentOrganization::run($organization, fn () => app(SetRetentionPolicies::class)->handle($admin, ['mail_dispatches' => 15]));

    expect(DB::table('retention_policies')->where('category', 'audit_log')->value('retention_days'))->toBe(300);
});

it('CA-014-35: without retention.manage the answer is 403 and nothing changes', function (OrganizationRole $role): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    $member = Memberships::attach(User::factory()->create(), $organization, $role);

    expect(fn () => CurrentOrganization::run($organization, fn () => app(SetRetentionPolicies::class)->handle($member, ['reviews' => 30])))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('retention_policies')->count())->toBe(0);
})->with([OrganizationRole::Organizer, OrganizationRole::Reviewer]);

it('CA-014-35: invalid periods are rejected', function (mixed $value): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();

    expect(fn () => CurrentOrganization::run($organization, fn () => app(SetRetentionPolicies::class)->handle($admin, ['reviews' => $value])))
        ->toThrow(ValidationException::class);

    expect(DB::table('retention_policies')->count())->toBe(0);
})->with(['zero' => [0], 'negative' => [-5], 'too long' => [40000], 'text' => ['mucho'], 'decimal' => ['10.5']]);

it('CA-014-35: every run is audited with totals and no personal data', function (): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    setPolicies($organization, [RetentionCategory::UnacceptedSubmissions->value => 100]);
    submissionAged($organization, SubmissionStatus::Rejected, 150, 'auditada.persona@example.test');

    retain($organization);

    $entry = Activity::query()->where('event', 'retention_applied')->sole();
    expect($entry->organization_id)->toBe($organization->id)
        ->and($entry->causer_id)->toBeNull()
        ->and($entry->properties['counts']['unaccepted_submissions'])->toBe(1)
        ->and(strtolower((string) json_encode($entry->properties)))->not->toContain('auditada')
        ->and(strtolower($entry->description))->not->toContain('auditada');
});

/* ------------------------------------------------------------------ CA-014-36 */

it('CA-014-36: with an audit_log period, expired audit records of the organization are deleted and the newer ones are kept', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    [$organizationB] = PersonFixtures::organizationWithAdmin();
    setPolicies($organization, [RetentionCategory::AuditLog->value => 100]);
    CurrentOrganization::run($organization, function () use ($organization, $admin): void {
        activity()->causedBy($admin)->event('old_fact')->withProperties(['organization_id' => $organization->id])->log('old_fact');
        activity()->causedBy($admin)->event('person_anonymized')->withProperties(['organization_id' => $organization->id])->log('person_anonymized');
        activity()->causedBy($admin)->event('recent_fact')->withProperties(['organization_id' => $organization->id])->log('recent_fact');
    });
    CurrentOrganization::run($organizationB, fn () => activity()->event('old_fact_b')->withProperties(['organization_id' => $organizationB->id])->log('old_fact_b'));
    Activity::query()->whereIn('event', ['old_fact', 'old_fact_b'])->update(['created_at' => now()->subDays(200)]);
    // Un hecho sensible, pero reciente: se conserva.
    Activity::query()->where('event', 'person_anonymized')->update(['created_at' => now()->subDays(10)]);

    $report = retain($organization);

    expect($report->countOf(RetentionCategory::AuditLog))->toBeGreaterThanOrEqual(1)
        ->and(Activity::query()->where('event', 'old_fact')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'recent_fact')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'person_anonymized')->count())->toBe(1)
        // Los hechos de otra organización no se tocan, aunque estén vencidos.
        ->and(Activity::query()->where('event', 'old_fact_b')->count())->toBe(1)
        // La propia ejecución queda registrada.
        ->and(Activity::query()->where('event', 'retention_applied')->count())->toBe(1);
});

/* ------------------------------------------------------------------ comando, programación y pantalla */

it('CA-014-33: the daily command applies every organization, tells which ones have no periods and fails when a category fails', function (): void {
    [$organizationA] = PersonFixtures::organizationWithAdmin();
    [$organizationB] = PersonFixtures::organizationWithAdmin();
    setPolicies($organizationA, [RetentionCategory::MailDispatches->value => 30]);
    CurrentOrganization::run($organizationA, fn () => MailDispatch::factory()->create(['created_at' => now()->subDays(60)]));

    $this->artisan('retention:apply')
        ->expectsOutputToContain("Organizacion {$organizationA->id}: mail_dispatches: 1.")
        ->expectsOutputToContain("Organizacion {$organizationB->id}: sin plazos de retencion.")
        ->assertSuccessful();

    $this->artisan('retention:apply', ['--organization' => 'no-existe'])->assertFailed();
});

it('CA-014-34: the command ends with an error when a submission could not be processed so that it is seen', function (): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    setPolicies($organization, [RetentionCategory::UnacceptedSubmissions->value => 100]);
    submissionAged($organization, SubmissionStatus::Rejected, 150, 'falla@example.test');
    $this->partialMock(PrivateUploads::class, fn ($mock) => $mock->shouldReceive('deleteConfirmed')->andReturn(false));

    $this->artisan('retention:apply')->expectsOutputToContain('fallo en unaccepted_submissions')->assertFailed();
});

it('CA-014-33: the job is scheduled once a day', function (): void {
    $this->artisan('schedule:list')->expectsOutputToContain('retention:apply')->assertSuccessful();
});

it('CA-014-35: the screen shows the periods, saves them and is only for retention.manage', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $organizer = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::Organizer);
    setPolicies($organization, [RetentionCategory::Reviews->value => 90]);

    Auth::login($organizer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($organization);
    CurrentOrganization::set($organization);
    expect(RetentionSettings::canAccess())->toBeFalse();

    Auth::login($admin);
    expect(RetentionSettings::canAccess())->toBeTrue();

    Livewire::test(RetentionSettings::class)
        ->assertSet('data.reviews', 90)
        ->assertSet('data.audit_log', null)
        ->fillForm(['reviews' => '', 'audit_log' => 400])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('privacy_admin.retention.notifications.saved'));

    expect(DB::table('retention_policies')->where('organization_id', $organization->id)->pluck('retention_days', 'category')->all())->toBe(['audit_log' => 400]);
});
