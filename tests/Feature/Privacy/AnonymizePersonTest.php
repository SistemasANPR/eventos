<?php

declare(strict_types=1);

use App\Actions\Privacy\AnonymizePerson;
use App\Actions\Submissions\ConsumeAccessToken;
use App\Actions\Submissions\CreateAccessToken;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\AccessLinkInvalid;
use App\Exceptions\Domain\AnonymizationSaltMissing;
use App\Exceptions\Domain\LastOrganizationAdmin;
use App\Exceptions\Domain\PersonCannotBeAnonymized;
use App\Exceptions\Domain\PersonDataNotFound;
use App\Exceptions\Domain\PersonFileDeletionFailed;
use App\Models\Activity;
use App\Models\AnonymizationLog;
use App\Models\Contact;
use App\Models\Event;
use App\Models\MailDispatch;
use App\Models\Organization;
use App\Models\SubmissionAccessToken;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Files\PrivateUploads;
use App\Support\Privacy\AnonymizationResult;
use App\Support\Privacy\PersonIdentity;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Memberships;
use Tests\Support\PersonFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PersonFixtures::prepare();
});

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Ejecuta la anonimización como `$actor` dentro de la organización.
 *
 * @param  array<string, string>  $data
 */
function anonymizeAs(User $actor, Organization $organization, array $data): AnonymizationResult
{
    return CurrentOrganization::run($organization, fn (): AnonymizationResult => app(AnonymizePerson::class)->handle($actor, $data));
}

/** Todo el contenido de la tabla como texto en minúsculas, para buscar en él el nombre o el correo de la persona. */
function textOf(string $table): string
{
    return strtolower((string) DB::table($table)->get()->toJson());
}

it('CA-014-21: replaces name, email, phone-like data, bio and personal comments, keeps aggregates and stamps anonymized_at', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);

    $result = anonymizeAs($admin, $organization, PersonFixtures::anonymizeData());

    expect($result->isAlreadyAnonymized())->toBeFalse()
        ->and($result->counts['contacts'])->toBe(1)
        ->and($result->counts['participants'])->toBe(1)
        ->and($result->counts['speakers'])->toBe(1);

    $contact = DB::table('contacts')->where('id', $person['contact']->id)->first();
    expect($contact->full_name)->toBe('Persona anonimizada')
        ->and($contact->email)->toBe("anonymized-{$person['contact']->id}@anonymized.invalid")
        ->and($contact->country)->toBeNull()
        ->and($contact->anonymized_at)->not->toBeNull();

    $participant = DB::table('submission_participants')->where('id', $person['participant']->id)->first();
    expect($participant->full_name)->toBe('Persona anonimizada')
        ->and($participant->first_name)->toBe('Persona')
        ->and($participant->last_name)->toBe('anonimizada')
        ->and($participant->job_title)->toBeNull()
        ->and($participant->company)->toBeNull()
        ->and($participant->bio)->toBeNull()
        ->and($participant->anonymized_at)->not->toBeNull();

    $speaker = DB::table('speakers')->where('id', $person['speaker']->id)->first();
    expect($speaker->first_name)->toBe('Persona')
        ->and($speaker->email)->toBeNull()
        ->and($speaker->bio)->toBeNull()
        ->and($speaker->photo_path)->toBeNull()
        ->and($speaker->is_published)->toBeFalse()
        ->and($speaker->anonymized_at)->not->toBeNull();

    $submission = DB::table('submissions')->where('id', $person['submission']->id)->first();
    expect($submission->author_comment)->toBe('[anonimizado]');

    // Agregados que se conservan: la propuesta y su estado, la coautora, las calificaciones.
    expect(DB::table('submissions')->where('id', $person['submission']->id)->exists())->toBeTrue();
    $coParticipant = DB::table('submission_participants')->where('id', $person['coParticipant']->id)->first();
    expect($coParticipant->full_name)->toBe('Luis Coautor Perez')
        ->and($coParticipant->anonymized_at)->toBeNull();
    expect(DB::table('submissions')->where('id', $person['submission']->id)->value('status'))->toBe('submitted');
});

it('CA-014-22: reports what is retained for legal reasons (nothing today: no billing data exists in R1)', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    PersonFixtures::person($organization);

    $result = anonymizeAs($admin, $organization, PersonFixtures::anonymizeData());

    expect($result->retained)->toBe([])
        ->and($result->toArray())->toHaveKey('retained');
});

it('CA-014-23: an administrator of organization A gets a 404 for a person who only exists in organization B and B is untouched', function (): void {
    [$organizationA, $adminA] = PersonFixtures::organizationWithAdmin();
    [$organizationB] = PersonFixtures::organizationWithAdmin();
    $personB = PersonFixtures::person($organizationB);

    expect(fn () => anonymizeAs($adminA, $organizationA, PersonFixtures::anonymizeData()))
        ->toThrow(PersonDataNotFound::class);

    expect(DB::table('contacts')->where('id', $personB['contact']->id)->value('full_name'))->toBe(PersonFixtures::NAME)
        ->and(DB::table('anonymization_log')->count())->toBe(0);
});

it('CA-014-24: without explicit confirmation (or with another email) nothing changes', function (Closure $tamper): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    $before = textOf('contacts').textOf('submission_participants').textOf('speakers');

    expect(fn () => anonymizeAs($admin, $organization, $tamper(PersonFixtures::anonymizeData())))->toThrow(ValidationException::class);

    expect(textOf('contacts').textOf('submission_participants').textOf('speakers'))->toBe($before)
        ->and(Storage::disk((string) config('filesystems.private_uploads_disk'))->exists($person['file']->storage_path))->toBeTrue()
        ->and(DB::table('anonymization_log')->count())->toBe(0);
})->with([
    'no confirmation' => [fn (array $data): array => array_diff_key($data, ['confirmation' => 1])],
    'another email' => [fn (array $data): array => [...$data, 'confirmation' => 'otra.persona@example.test']],
    'unknown reason' => [fn (array $data): array => [...$data, 'reason' => 'porque si']],
    'no reason' => [fn (array $data): array => array_diff_key($data, ['reason' => 1])],
]);

it('CA-014-24: the confirmation ignores case and surrounding spaces', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    PersonFixtures::person($organization);

    $result = anonymizeAs($admin, $organization, ['email' => '  ANA.TORRES@example.test ', 'reason' => 'consent_withdrawn', 'confirmation' => 'ana.torres@EXAMPLE.test']);

    expect($result->counts['contacts'])->toBe(1);
});

it('CA-014-25: the access link of an anonymized contact is rejected with the generic error', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    $raw = str_repeat('a1B2c3D4', 4);
    CurrentOrganization::run($organization, fn () => SubmissionAccessToken::factory()->create([
        'submission_id' => $person['submission']->id,
        'token_hash' => CreateAccessToken::hash($raw),
    ]));
    $event = CurrentOrganization::run($organization, fn (): Event => Event::query()->findOrFail($person['submission']->event_id));

    anonymizeAs($admin, $organization, PersonFixtures::anonymizeData());

    expect(fn () => CurrentOrganization::run($organization, fn () => app(ConsumeAccessToken::class)->handle($event, $raw)))
        ->toThrow(AccessLinkInvalid::class);

    expect(CurrentOrganization::run($organization, fn (): int => SubmissionAccessToken::query()->where('submission_id', $person['submission']->id)->whereNull('consumed_at')->count()))->toBe(0);
});

it('CA-014-27: name and email no longer appear in the audit log, participants or free-text answers (the audit keeps the fact)', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    $auditEntries = Activity::query()->count();

    anonymizeAs($admin, $organization, PersonFixtures::anonymizeData());

    foreach (['activity_log', 'submission_participants', 'submissions', 'contacts', 'speakers', 'users', 'review_answers', 'review_history'] as $table) {
        // La tabla de usuarios conserva el nombre del administrador y otros: se busca solo lo de la persona.
        expect(textOf($table))->not->toContain('ana.torres@example.test')
            ->and(textOf($table))->not->toContain('ana torres prieto');
    }

    // El hecho se conserva: las entradas siguen, con su evento, y la coautora sigue donde estaba.
    expect(Activity::query()->count())->toBeGreaterThanOrEqual($auditEntries)
        ->and(Activity::query()->where('event', 'note_added')->count())->toBe(1)
        ->and(textOf('submission_participants'))->toContain('luis.coautor@example.test');
    $answers = DB::table('submissions')->where('id', $person['submission']->id)->value('answers');
    expect((string) $answers)->toContain('[anonimizado]');
});

it('CA-014-28: deletes the uploaded files and the speaker photo from the disk and keeps only type, size and dates', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    $path = $person['file']->storage_path;
    $photo = (string) $person['speaker']->photo_path;
    expect(Storage::disk((string) config('filesystems.private_uploads_disk'))->exists($path))->toBeTrue()
        ->and(MediaDisk::get()->exists($photo))->toBeTrue();

    $result = anonymizeAs($admin, $organization, PersonFixtures::anonymizeData());

    expect(Storage::disk((string) config('filesystems.private_uploads_disk'))->exists($path))->toBeFalse()
        ->and(MediaDisk::get()->exists($photo))->toBeFalse()
        ->and($result->counts['files_deleted'])->toBe(2);

    $file = DB::table('submission_files')->where('id', $person['file']->id)->first();
    expect($file->original_name)->toBe('archivo-anonimizado')
        ->and($file->storage_path)->toBe("anonymized/{$person['file']->id}")
        ->and($file->sha256)->toBe(str_repeat('0', 64))
        ->and($file->mime_type)->toBe('application/pdf')
        ->and($file->size_bytes)->toBe(1024)
        ->and($file->uploaded_at)->not->toBeNull()
        ->and($file->anonymized_at)->not->toBeNull();
});

it('CA-014-29: unlinks the mail dispatches, replaces the review comments, invalidates the access tokens and records the fingerprint', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    $otherDispatchHash = hash_hmac('sha256', 'nadie@example.test', (string) config('app.key'));
    $other = CurrentOrganization::run($organization, fn () => MailDispatch::factory()->create(['recipient_hash' => $otherDispatchHash]));

    anonymizeAs($admin, $organization, PersonFixtures::anonymizeData());

    $hash = DB::table('mail_dispatches')->where('id', $person['dispatch']->id)->value('recipient_hash');
    expect($hash)->not->toBe(PersonIdentity::dispatchHash(PersonFixtures::EMAIL))
        ->and($hash)->toBe(hash('sha256', 'anonymized:'.$person['dispatch']->id))
        ->and(DB::table('mail_dispatches')->where('id', $other->id)->value('recipient_hash'))->toBe($otherDispatchHash);

    // Comentario hecho por la persona como evaluadora: sustituido, en la respuesta y en el historial; la calificación no existe aquí.
    expect(DB::table('review_answers')->where('id', $person['answer']->id)->value('value_text'))->toBe('[anonimizado]')
        ->and(json_encode(DB::table('review_history')->where('id', $person['revision']->id)->value('answers')))->toContain('[anonimizado]');
    // Comentario de otra persona evaluadora sobre ella: conserva el texto sin su nombre.
    expect(DB::table('review_answers')->where('id', $person['aboutAnswer']->id)->value('value_text'))->toBe('La autora [anonimizado] no cita fuentes');

    $token = DB::table('submission_access_tokens')->where('id', $person['token']->id)->first();
    expect($token->consumed_at)->not->toBeNull()
        ->and($token->token_hash)->not->toBe($person['token']->token_hash);

    $log = AnonymizationLog::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->get();
    expect($log)->toHaveCount(1)
        ->and(DB::table('anonymization_log')->first()->email_hash)->toBe(PersonIdentity::organizationHash(PersonFixtures::EMAIL, $organization->id))
        ->and(strtolower((string) DB::table('anonymization_log')->get()->toJson()))->not->toContain('ana');

    $change = DB::table('submission_status_changes')->where('id', $person['change']->id)->first();
    expect($change->comment)->toBe('[anonimizado]')->and($change->author_comment)->toBe('[anonimizado]');
});

it('CA-014-29: the fingerprint is different in two organizations for the same email', function (): void {
    [$organizationA] = PersonFixtures::organizationWithAdmin();
    [$organizationB] = PersonFixtures::organizationWithAdmin();

    expect(PersonIdentity::organizationHash(PersonFixtures::EMAIL, $organizationA->id))->not->toBe(PersonIdentity::organizationHash(PersonFixtures::EMAIL, $organizationB->id))
        ->and(PersonIdentity::organizationHash('ANA.TORRES@example.test ', $organizationA->id))->toBe(PersonIdentity::organizationHash(PersonFixtures::EMAIL, $organizationA->id));
});

it('CA-014-30: without personal_data.manage the answer is 403 and nothing changes', function (OrganizationRole $role): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    $member = Memberships::attach(User::factory()->create(), $organization, $role);

    expect(fn () => anonymizeAs($member, $organization, PersonFixtures::anonymizeData()))->toThrow(AuthorizationException::class);

    expect(DB::table('contacts')->where('id', $person['contact']->id)->value('full_name'))->toBe(PersonFixtures::NAME)
        ->and(Storage::disk((string) config('filesystems.private_uploads_disk'))->exists($person['file']->storage_path))->toBeTrue();
})->with([OrganizationRole::Organizer, OrganizationRole::Reviewer]);

it('CA-014-30: a user who is not a member gets 403', function (): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    PersonFixtures::person($organization);

    expect(fn () => anonymizeAs(User::factory()->create(), $organization, PersonFixtures::anonymizeData()))->toThrow(AuthorizationException::class);
});

it('CA-014-21: anonymizing is idempotent: the second time it reports already_anonymized and changes nothing', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    PersonFixtures::person($organization);

    anonymizeAs($admin, $organization, PersonFixtures::anonymizeData());
    $snapshot = textOf('contacts').textOf('anonymization_log').textOf('activity_log');

    $again = anonymizeAs($admin, $organization, PersonFixtures::anonymizeData());

    expect($again->isAlreadyAnonymized())->toBeTrue()
        ->and($again->total())->toBe(0)
        ->and(textOf('contacts').textOf('anonymization_log').textOf('activity_log'))->toBe($snapshot)
        ->and(DB::table('anonymization_log')->count())->toBe(1);
});

it('CA-014-21: the audit entry has the actor, the reason and totals, and no personal data', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    PersonFixtures::person($organization);

    anonymizeAs($admin, $organization, PersonFixtures::anonymizeData());

    $entry = Activity::query()->where('event', 'person_anonymized')->sole();
    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->organization_id)->toBe($organization->id)
        ->and($entry->properties['reason'])->toBe('data_subject_request')
        ->and($entry->properties['counts']['contacts'])->toBe(1)
        ->and(strtolower((string) json_encode($entry->properties)))->not->toContain('ana')
        ->and(strtolower($entry->description))->not->toContain('ana');
});

it('CA-014-21: a missing salt rejects the anonymization before touching anything', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    config(['privacy.anonymization_salt' => null]);

    expect(fn () => anonymizeAs($admin, $organization, PersonFixtures::anonymizeData()))->toThrow(AnonymizationSaltMissing::class);

    expect(Storage::disk((string) config('filesystems.private_uploads_disk'))->exists($person['file']->storage_path))->toBeTrue()
        ->and(DB::table('contacts')->where('id', $person['contact']->id)->value('full_name'))->toBe(PersonFixtures::NAME);
});

it('CA-014-28: if the disk cannot delete a file the error surfaces and the database is untouched, so it can be repeated', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    $this->partialMock(PrivateUploads::class, fn ($mock) => $mock->shouldReceive('deleteConfirmed')->andReturn(false));

    expect(fn () => anonymizeAs($admin, $organization, PersonFixtures::anonymizeData()))->toThrow(PersonFileDeletionFailed::class);

    expect(DB::table('contacts')->where('id', $person['contact']->id)->value('full_name'))->toBe(PersonFixtures::NAME)
        ->and(DB::table('submission_files')->where('id', $person['file']->id)->value('anonymized_at'))->toBeNull()
        ->and(DB::table('anonymization_log')->count())->toBe(0);
});

/* ------------------------------------------------------------------ la cuenta de acceso */

it('CA-014-21: anonymizing a member whose only organization is this one anonymizes the account, its devices and its 2FA', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    $oldHash = DB::table('users')->where('id', $person['user']->id)->value('password');

    $result = anonymizeAs($admin, $organization, PersonFixtures::anonymizeData());

    $user = DB::table('users')->where('id', $person['user']->id)->first();
    expect($result->counts['accounts'])->toBe(1)
        ->and($user->name)->toBe('Persona anonimizada')
        ->and($user->email)->toBe("anonymized-{$person['user']->id}@anonymized.invalid")
        ->and($user->password)->not->toBe($oldHash)
        ->and($user->app_authentication_secret)->toBeNull()
        ->and($user->app_authentication_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->remember_token)->toBeNull()
        ->and($user->anonymized_at)->not->toBeNull()
        ->and(DB::table('known_devices')->where('user_id', $person['user']->id)->count())->toBe(0);

    $membership = DB::table('organization_user')->where('user_id', $person['user']->id)->first();
    expect($membership->status)->toBe('deactivated');
    // La auditoría de accesos de la cuenta pierde la IP y la etiqueta del dispositivo.
    $auth = Activity::query()->where('causer_id', $person['user']->id)->where('event', 'new_device_login')->sole();
    expect($auth->properties->has('ip'))->toBeFalse()->and($auth->properties->has('device'))->toBeFalse();
});

it('CA-014-21: a member who also belongs to another organization keeps the account; here it loses the membership and its review comments', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    $other = Memberships::organization();
    Memberships::attach($person['user'], $other, OrganizationRole::Organizer);

    $result = anonymizeAs($admin, $organization, PersonFixtures::anonymizeData());

    $user = DB::table('users')->where('id', $person['user']->id)->first();
    expect($result->notes)->toContain(AnonymizationResult::NOTE_ACCOUNT_KEPT_OTHER_ORGANIZATIONS)
        ->and($result->counts['accounts'])->toBe(0)
        ->and($user->email)->toBe(PersonFixtures::EMAIL)
        ->and($user->anonymized_at)->toBeNull()
        ->and(DB::table('organization_user')->where('user_id', $person['user']->id)->where('organization_id', $organization->id)->value('status'))->toBe('deactivated')
        ->and(DB::table('organization_user')->where('user_id', $person['user']->id)->where('organization_id', $other->id)->value('status'))->toBe('active')
        ->and(DB::table('review_answers')->where('id', $person['answer']->id)->value('value_text'))->toBe('[anonimizado]')
        ->and(DB::table('known_devices')->where('user_id', $person['user']->id)->count())->toBe(1);
});

it('CA-014-21: the last active administrator cannot be anonymized and nothing changes, not even the files', function (): void {
    [$organization] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    $actor = null;
    CurrentOrganization::run($organization, function () use ($organization, $person, &$actor): void {
        // La persona es la única administradora activa; quien pide la anonimización lo hace con el permiso concedido directamente.
        foreach (User::query()->activeMembersOf($organization)->role(OrganizationRole::OrgAdmin->value)->get() as $existing) {
            $organization->users()->updateExistingPivot($existing->id, ['status' => 'deactivated', 'deactivated_at' => now()]);
        }
        $person['user']->assignRole(OrganizationRole::OrgAdmin->value);
        $actor = User::factory()->create();
        $organization->users()->attach($actor->id, ['status' => 'active']);
        $actor->givePermissionTo('personal_data.manage');
    });

    expect(fn () => anonymizeAs($actor, $organization, PersonFixtures::anonymizeData()))->toThrow(LastOrganizationAdmin::class);

    expect(DB::table('contacts')->where('id', $person['contact']->id)->value('full_name'))->toBe(PersonFixtures::NAME)
        ->and(Storage::disk((string) config('filesystems.private_uploads_disk'))->exists($person['file']->storage_path))->toBeTrue()
        ->and(DB::table('users')->where('id', $person['user']->id)->value('anonymized_at'))->toBeNull();
});

it('CA-014-21: an administrator who is not the last one can be anonymized', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $person = PersonFixtures::person($organization);
    CurrentOrganization::run($organization, fn () => $person['user']->assignRole(OrganizationRole::OrgAdmin->value));

    $result = anonymizeAs($admin, $organization, PersonFixtures::anonymizeData());

    expect($result->counts['accounts'])->toBe(1);
});

it('CA-014-21: nobody can anonymize their own account', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();

    expect(fn () => anonymizeAs($admin, $organization, PersonFixtures::anonymizeData($admin->email)))->toThrow(PersonCannotBeAnonymized::class);

    expect(DB::table('users')->where('id', $admin->id)->value('anonymized_at'))->toBeNull();
});

it('CA-014-21: a platform account cannot be anonymized from an organization', function (): void {
    [$organization, $admin] = PersonFixtures::organizationWithAdmin();
    $platform = User::factory()->platformAdmin()->create(['email' => 'plataforma@example.test']);
    Memberships::attach($platform, $organization, OrganizationRole::Organizer);

    expect(fn () => anonymizeAs($admin, $organization, PersonFixtures::anonymizeData('plataforma@example.test')))->toThrow(PersonCannotBeAnonymized::class);

    expect(DB::table('users')->where('id', $platform->id)->value('email'))->toBe('plataforma@example.test');
});

it('CA-014-23: anonymizing in organization A does not touch the same email in organization B', function (): void {
    [$organizationA, $adminA] = PersonFixtures::organizationWithAdmin();
    [$organizationB] = PersonFixtures::organizationWithAdmin();
    $personA = PersonFixtures::person($organizationA);
    $personB = PersonFixtures::person($organizationB, withAccount: false);
    anonymizeAs($adminA, $organizationA, PersonFixtures::anonymizeData());

    expect(DB::table('contacts')->where('id', $personA['contact']->id)->value('anonymized_at'))->not->toBeNull()
        ->and(DB::table('contacts')->where('id', $personB['contact']->id)->value('anonymized_at'))->toBeNull()
        ->and(DB::table('contacts')->where('id', $personB['contact']->id)->value('email'))->toBe(PersonFixtures::EMAIL)
        ->and(Storage::disk((string) config('filesystems.private_uploads_disk'))->exists($personB['file']->storage_path))->toBeTrue()
        ->and(DB::table('anonymization_log')->where('organization_id', $organizationB->id)->count())->toBe(0);
});

it('CA-014-29: models used by the person are scoped, so the engine fails without an organization context', function (): void {
    expect(fn () => Contact::query()->count())->toThrow(MissingOrganizationContext::class);
});
