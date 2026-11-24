<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\OrganizationRole;
use App\Models\Contact;
use App\Models\KnownDevice;
use App\Models\MailDispatch;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewAssignment;
use App\Models\ReviewRevision;
use App\Models\Speaker;
use App\Models\Submission;
use App\Models\SubmissionAccessToken;
use App\Models\SubmissionFile;
use App\Models\SubmissionParticipant;
use App\Models\SubmissionStatusChange;
use App\Models\User;
use App\Support\Files\MediaDisk;
use App\Support\Privacy\PersonIdentity;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Storage;

/**
 * Ayudas de prueba de privacidad (plan 014): una persona con datos en TODOS los módulos del inventario, para
 * probar la exportación, la anonimización y la retención. Todo es ficticio.
 */
final class PersonFixtures
{
    public const SALT = 'sal-de-prueba-0123456789';

    public const EMAIL = 'ana.torres@example.test';

    public const NAME = 'Ana Torres Prieto';

    /** Fija la sal de las huellas y los discos falsos (privado y de medios). */
    public static function prepare(): void
    {
        config(['privacy.anonymization_salt' => self::SALT]);
        Storage::fake((string) config('filesystems.private_uploads_disk'));
        Storage::fake(MediaDisk::name());
    }

    /** @return array{0: Organization, 1: User} la organización y su administrador (con `personal_data.manage`) */
    public static function organizationWithAdmin(): array
    {
        $organization = Memberships::organization();
        $admin = Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);
        // Un segundo administrador: el primero nunca es «el último» salvo que la prueba lo quiera.
        Memberships::attach(User::factory()->create(), $organization, OrganizationRole::OrgAdmin);

        return [$organization, $admin];
    }

    /**
     * La persona de `EMAIL`: contacto, propuesta (con coautora, archivo, enlace de acceso y decisión), ponente con
     * fotografía, cuenta de evaluadora con una evaluación hecha, dispositivo conocido, 2FA, un envío de correo y
     * entradas de auditoría con su nombre y su correo. Se crea dentro de la organización. Con `$withAccount` falso, la
     * cuenta de evaluadora lleva otro correo (el de una cuenta es único en la plataforma: para repetir a la persona en
     * dos organizaciones).
     *
     * @return array{
     *     contact: Contact, submission: Submission, participant: SubmissionParticipant, coParticipant: SubmissionParticipant,
     *     file: SubmissionFile, token: SubmissionAccessToken, change: SubmissionStatusChange, speaker: Speaker, user: User,
     *     assignment: ReviewAssignment, answer: ReviewAnswer, revision: ReviewRevision, aboutAnswer: ReviewAnswer,
     *     dispatch: MailDispatch, device: KnownDevice,
     * }
     */
    public static function person(Organization $organization, string $email = self::EMAIL, bool $withAccount = true): array
    {
        return CurrentOrganization::run($organization, function () use ($organization, $email, $withAccount): array {
            $privateDisk = Storage::disk((string) config('filesystems.private_uploads_disk'));

            $contact = Contact::factory()->create(['full_name' => self::NAME, 'email' => $email, 'country' => 'MX']);

            $submission = Submission::factory()->create([
                'title' => 'Charla de '.self::NAME,
                'answers' => ['abstract' => 'Escribeme a '.strtoupper($email).' o pregunta por '.self::NAME, 'extra' => ['contact' => $email]],
                'author_comment' => 'Hola Ana, gracias',
            ]);
            $participant = SubmissionParticipant::factory()->create([
                'submission_id' => $submission->id,
                'contact_id' => $contact->id,
                'full_name' => self::NAME,
                'email' => $email,
                'country' => 'MX',
                'job_title' => 'Ingeniera',
                'company' => 'ACME',
                'bio' => 'Mi biografia',
            ]);
            // El correo de la coautora es único por organización: el de siempre salvo que se repita la fixture.
            $coEmail = $email === self::EMAIL ? 'luis.coautor@example.test' : 'coautor.'.md5($email).'@example.test';
            $otherContact = Contact::factory()->create(['full_name' => 'Luis Coautor Perez', 'email' => $coEmail]);
            $coParticipant = SubmissionParticipant::factory()->secondary()->create([
                'submission_id' => $submission->id,
                'contact_id' => $otherContact->id,
                'full_name' => 'Luis Coautor Perez',
                'email' => $coEmail,
            ]);

            $file = SubmissionFile::factory()->attachedTo($submission->id)->create([
                'call_id' => $submission->call_for_submissions_id,
                'original_name' => 'cv-ana-torres.pdf',
            ]);
            $privateDisk->put($file->storage_path, 'contenido del archivo');

            $token = SubmissionAccessToken::factory()->create(['submission_id' => $submission->id]);
            $change = SubmissionStatusChange::factory()->create([
                'submission_id' => $submission->id,
                'comment' => 'Motivo interno con datos personales',
                'author_comment' => 'Enhorabuena Ana',
            ]);

            $speaker = Speaker::factory()->create([
                'contact_id' => $contact->id,
                'first_name' => 'Ana',
                'last_name' => 'Torres Prieto',
                'email' => $email,
                'country' => 'MX',
                'company' => 'ACME',
                'job_title' => 'Ingeniera',
                'tagline' => 'Habla de sus cosas',
                'bio' => '<p>Biografia publica</p>',
                'photo_path' => "organizations/{$organization->id}/speakers/foto.jpg",
                'social_links' => [['network' => 'x', 'url' => 'https://x.test/ana']],
            ]);
            MediaDisk::get()->put((string) $speaker->photo_path, 'imagen');

            // La misma persona, además, evalúa propuestas con una cuenta de acceso (el correo de una cuenta es único en la plataforma).
            $user = User::factory()->create(['name' => self::NAME, 'email' => $withAccount ? $email : 'otra.cuenta.'.$organization->id.'@example.test']);
            Memberships::attach($user, $organization, OrganizationRole::Reviewer);
            TwoFactor::enable($user);
            $device = KnownDevice::query()->create(['user_id' => $user->id, 'fingerprint_hash' => hash('sha256', 'navegador'), 'last_seen_at' => now()]);

            $assignment = ReviewAssignment::factory()->create(['reviewer_id' => $user->id]);
            $review = Review::factory()->submitted()->create(['review_assignment_id' => $assignment->id]);
            $answer = ReviewAnswer::factory()->text('Mi opinion como evaluadora')->create(['review_id' => $review->id]);
            $revision = ReviewRevision::factory()->create([
                'review_id' => $review->id,
                'answers' => [['rubric_criterion_id' => $answer->rubric_criterion_id, 'value_int' => null, 'value_text' => 'Mi opinion como evaluadora']],
            ]);

            // Un comentario de OTRO evaluador sobre la propuesta de Ana, que la nombra.
            $aboutAssignment = ReviewAssignment::factory()->create(['submission_id' => $submission->id]);
            $aboutReview = Review::factory()->submitted()->create(['review_assignment_id' => $aboutAssignment->id]);
            $aboutAnswer = ReviewAnswer::factory()->text('La autora '.self::NAME.' no cita fuentes')->create(['review_id' => $aboutReview->id]);

            $dispatch = MailDispatch::factory()->create(['recipient_hash' => PersonIdentity::dispatchHash($email)]);

            $admin = $organization->users()->role(OrganizationRole::OrgAdmin->value)->first();
            activity()->causedBy($admin)->performedOn($contact)->event('note_added')
                ->withProperties(['organization_id' => $organization->id, 'note' => 'Contacto '.self::NAME.' <'.$email.'>'])
                ->log('Nota sobre '.self::NAME);
            activity('auth')->causedBy($user)->event('new_device_login')
                ->withProperties(['ip' => '203.0.113.7', 'device' => 'Chrome en Windows'])
                ->log('new_device_login');

            return [
                'contact' => $contact, 'submission' => $submission, 'participant' => $participant, 'coParticipant' => $coParticipant,
                'file' => $file, 'token' => $token, 'change' => $change, 'speaker' => $speaker, 'user' => $user,
                'assignment' => $assignment, 'answer' => $answer, 'revision' => $revision, 'aboutAnswer' => $aboutAnswer,
                'dispatch' => $dispatch, 'device' => $device,
            ];
        });
    }

    /** @return array<string, string> datos válidos de `AnonymizePerson` */
    public static function anonymizeData(string $email = self::EMAIL): array
    {
        return ['email' => $email, 'reason' => 'data_subject_request', 'confirmation' => $email];
    }
}
