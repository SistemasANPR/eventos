<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use App\Exceptions\Domain\PersonFileDeletionFailed;
use App\Models\Activity;
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
use App\Support\Auth\AccessThrottle;
use App\Support\Files\MediaDisk;
use App\Support\Files\PrivateUploads;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Sustituye los datos personales de una persona por valores no identificables, módulo por módulo (plan 014,
 * DA-4). Cada método es idempotente (lo ya anonimizado no se vuelve a tocar), actúa solo sobre lo que se le
 * indica y dentro de la organización en contexto, y devuelve cuántas filas cambió. Quien lo usa
 * (`AnonymizePerson`, `ReapplyAnonymizations`, `ApplyRetentionPolicy`) decide el alcance, la transacción y la
 * auditoría. `PersonalDataInventory` declara qué columnas cubre cada método y su guardiana lo comprueba.
 *
 * Los archivos se borran ANTES de tocar la base (`deleteBlobs`): si el disco falla, la base conserva las
 * rutas y la operación se repite; el orden inverso dejaría archivos sin ruta que los localice.
 */
final class PersonDataEraser
{
    public const NAME = 'Persona anonimizada';

    public const FIRST_NAME = 'Persona';

    public const LAST_NAME = 'anonimizada';

    /** Dominio reservado (RFC 2606): un correo anonimizado no puede llegar a nadie. */
    public const EMAIL_DOMAIN = 'anonymized.invalid';

    /** Código de «región desconocida» (CLDR): `country` es obligatorio en los participantes. */
    public const COUNTRY = 'ZZ';

    public const FILE_NAME = 'archivo-anonimizado';

    public function __construct(private readonly PrivateUploads $uploads) {}

    /** Huella que identifica un envío de correo ya desvinculado de su destinatario. */
    public static function anonymousDispatchHash(string $dispatchId): string
    {
        return hash('sha256', 'anonymized:'.$dispatchId);
    }

    /* ------------------------------------------------------------------ archivos */

    /**
     * Borra del disco los archivos de las propuestas indicadas y las fotografías de los ponentes. Debe
     * llamarse antes que cualquier cambio en la base. Un archivo que ya no existe cuenta como borrado.
     *
     * @param  list<string>  $submissionIds
     * @param  list<string>  $speakerIds
     * @return int archivos y fotografías que se borraron del disco
     *
     * @throws PersonFileDeletionFailed si el disco no logra borrar alguno
     */
    public function deleteBlobs(array $submissionIds, array $speakerIds): int
    {
        $deleted = 0;

        $files = SubmissionFile::withTrashed()->whereIn('submission_id', $submissionIds)->whereNull('anonymized_at')->get(['id', 'storage_path']);

        foreach ($files as $file) {
            if (! $this->uploads->deleteConfirmed($file->storage_path)) {
                throw PersonFileDeletionFailed::onDisk($this->uploads->diskName());
            }
            $deleted++;
        }

        $photos = Speaker::withTrashed()->whereIn('id', $speakerIds)->whereNotNull('photo_path')->pluck('photo_path');
        $media = MediaDisk::get();

        foreach ($photos as $path) {
            if (! $this->deleteFrom($media, (string) $path)) {
                throw PersonFileDeletionFailed::onDisk(MediaDisk::name());
            }
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Anonimiza las fichas de archivo de las propuestas (el contenido ya se borró con `deleteBlobs`):
     * quedan tipo, tamaño y fechas.
     *
     * @param  list<string>  $submissionIds
     */
    public function eraseFiles(array $submissionIds): int
    {
        return SubmissionFile::withTrashed()
            ->whereIn('submission_id', $submissionIds)
            ->whereNull('anonymized_at')
            ->update([
                'original_name' => self::FILE_NAME,
                'storage_path' => DB::raw("'anonymized/' || id::text"),
                'sha256' => str_repeat('0', 64),
                'anonymized_at' => now(),
            ]);
    }

    /* ------------------------------------------------------------------ personas */

    /** @param  list<string>  $participantIds */
    public function eraseParticipants(array $participantIds): int
    {
        return SubmissionParticipant::query()
            ->whereIn('id', $participantIds)
            ->whereNull('anonymized_at')
            ->update([
                'full_name' => self::NAME,
                // Las partes del nombre existen o no existen juntas (CHECK de la base) y deben componer `full_name`.
                'first_name' => DB::raw('CASE WHEN first_name IS NULL THEN NULL ELSE '."'".self::FIRST_NAME."'".' END'),
                'last_name' => DB::raw('CASE WHEN last_name IS NULL THEN NULL ELSE '."'".self::LAST_NAME."'".' END'),
                'email' => DB::raw("'anonymized-' || id::text || '@".self::EMAIL_DOMAIN."'"),
                'country' => self::COUNTRY,
                'job_title' => null,
                'company' => null,
                'bio' => null,
                'anonymized_at' => now(),
            ]);
    }

    /** @param  list<string>  $contactIds */
    public function eraseContacts(array $contactIds): int
    {
        return Contact::query()
            ->whereIn('id', $contactIds)
            ->whereNull('anonymized_at')
            ->update([
                'full_name' => self::NAME,
                'email' => DB::raw("'anonymized-' || id::text || '@".self::EMAIL_DOMAIN."'"),
                'country' => null,
                'anonymized_at' => now(),
            ]);
    }

    /**
     * La ficha del ponente deja de ser pública: su nombre ya no es el de nadie.
     *
     * @param  list<string>  $speakerIds
     */
    public function eraseSpeakers(array $speakerIds): int
    {
        return Speaker::withTrashed()
            ->whereIn('id', $speakerIds)
            ->whereNull('anonymized_at')
            ->update([
                'first_name' => self::FIRST_NAME,
                'last_name' => self::LAST_NAME,
                'email' => null,
                'country' => null,
                'company' => null,
                'job_title' => null,
                'tagline' => null,
                'bio' => null,
                'photo_path' => null,
                'social_links' => '[]',
                'is_published' => false,
                'anonymized_at' => now(),
            ]);
    }

    /* ------------------------------------------------------------------ propuestas */

    /**
     * Invalida los enlaces de acceso del autor: ni se pueden consumir ni su hash sirve de nada.
     *
     * @param  list<string>  $submissionIds
     */
    public function invalidateAccessTokens(array $submissionIds): int
    {
        $count = 0;

        SubmissionAccessToken::query()->whereIn('submission_id', $submissionIds)->each(function (SubmissionAccessToken $token) use (&$count): void {
            $token->forceFill([
                'token_hash' => hash('sha256', Str::random(64)),
                'expires_at' => now(),
                'consumed_at' => $token->consumed_at ?? now(),
            ])->save();
            $count++;
        });

        return $count;
    }

    /**
     * Sustituye el comentario de la decisión para el autor y el motivo interno del historial (texto
     * libre que puede incluir datos personales). Los cambios de estado, quién decidió y cuándo se conservan.
     *
     * @param  list<string>  $submissionIds
     */
    public function eraseDecisionComments(array $submissionIds): int
    {
        $submissions = Submission::withTrashed()
            ->whereIn('id', $submissionIds)
            ->whereNotNull('author_comment')
            ->where('author_comment', '<>', Redactor::TEXT)
            ->update(['author_comment' => Redactor::TEXT]);

        $changes = SubmissionStatusChange::query()->whereIn('submission_id', $submissionIds)->where(
            fn ($query) => $query
                ->where(fn ($comment) => $comment->whereNotNull('comment')->where('comment', '<>', Redactor::TEXT))
                ->orWhere(fn ($comment) => $comment->whereNotNull('author_comment')->where('author_comment', '<>', Redactor::TEXT)),
        )->update([
            'comment' => DB::raw('CASE WHEN comment IS NULL THEN NULL ELSE '."'".Redactor::TEXT."'".' END'),
            'author_comment' => DB::raw('CASE WHEN author_comment IS NULL THEN NULL ELSE '."'".Redactor::TEXT."'".' END'),
        ]);

        return $submissions + $changes;
    }

    /**
     * Quita el nombre y el correo del título y de las respuestas del formulario de las propuestas.
     *
     * @param  list<string>  $submissionIds
     * @param  list<string>  $needles
     */
    public function redactSubmissionTexts(array $submissionIds, array $needles): int
    {
        if ($needles === []) {
            return 0;
        }

        $count = 0;

        Submission::withTrashed()->whereIn('id', $submissionIds)->each(function (Submission $submission) use ($needles, &$count): void {
            $title = Redactor::text($submission->title, $needles);
            $answers = Redactor::deep($submission->answers, $needles);

            if ($title === $submission->title && $answers === $submission->answers) {
                return;
            }

            $submission->forceFill(['title' => $title, 'answers' => $answers])->save();
            $count++;
        });

        return $count;
    }

    /* ------------------------------------------------------------------ evaluaciones */

    /**
     * Texto de las evaluaciones que HIZO un evaluador (sus comentarios son dato personal suyo): se
     * sustituye; se conservan las calificaciones, que ya no llevan identidad.
     *
     * @return int respuestas e historiales cambiados
     */
    public function eraseReviewerComments(string $reviewerId): int
    {
        $reviewIds = Review::withTrashed()
            ->whereIn('review_assignment_id', ReviewAssignment::query()->where('reviewer_id', $reviewerId)->select('id'))
            ->pluck('id')
            ->all();

        return $this->rewriteReviewTexts($reviewIds, static fn (string $text): string => Redactor::TEXT);
    }

    /**
     * Quita el nombre y el correo de la persona de los comentarios de las evaluaciones de sus propuestas.
     *
     * @param  list<string>  $submissionIds
     * @param  list<string>  $needles
     */
    public function redactReviewComments(array $submissionIds, array $needles): int
    {
        if ($needles === []) {
            return 0;
        }

        $reviewIds = Review::withTrashed()
            ->whereIn('review_assignment_id', ReviewAssignment::query()->whereIn('submission_id', $submissionIds)->select('id'))
            ->pluck('id')
            ->all();

        return $this->rewriteReviewTexts($reviewIds, static fn (string $text): string => Redactor::text($text, $needles));
    }

    /**
     * Sustituye el texto de TODAS las evaluaciones de las propuestas (retención de evaluaciones, F5).
     *
     * @param  list<string>  $reviewIds
     */
    public function eraseReviewComments(array $reviewIds): int
    {
        return $this->rewriteReviewTexts($reviewIds, static fn (string $text): string => Redactor::TEXT);
    }

    /**
     * @param  list<string>  $reviewIds
     * @param  callable(string): string  $rewrite
     */
    private function rewriteReviewTexts(array $reviewIds, callable $rewrite): int
    {
        $count = 0;

        ReviewAnswer::query()->whereIn('review_id', $reviewIds)->whereNotNull('value_text')->each(function (ReviewAnswer $answer) use ($rewrite, &$count): void {
            $text = (string) $answer->value_text;
            $new = $rewrite($text);

            if ($new !== $text) {
                $answer->forceFill(['value_text' => $new])->save();
                $count++;
            }
        });

        ReviewRevision::query()->whereIn('review_id', $reviewIds)->each(function (ReviewRevision $revision) use ($rewrite, &$count): void {
            $changed = false;
            $answers = array_map(function (array $answer) use ($rewrite, &$changed): array {
                $text = $answer['value_text'] ?? null;

                if (is_string($text) && $rewrite($text) !== $text) {
                    $answer['value_text'] = $rewrite($text);
                    $changed = true;
                }

                return $answer;
            }, $revision->answers);

            if ($changed) {
                $revision->forceFill(['answers' => $answers])->save();
                $count++;
            }
        });

        return $count;
    }

    /* ------------------------------------------------------------------ correos */

    /**
     * Rompe el vínculo de los envíos de correo con sus destinatarios: la huella pasa a ser una que no
     * corresponde a ningún correo. Se conservan la clave, el estado y las fechas.
     *
     * @param  list<string>  $emails
     */
    public function eraseMailDispatches(array $emails): int
    {
        $hashes = array_map(PersonIdentity::dispatchHash(...), $emails);

        return $this->anonymizeDispatches(MailDispatch::query()->whereIn('recipient_hash', $hashes));
    }

    /**
     * @param  Builder<MailDispatch>  $query
     */
    public function anonymizeDispatches(Builder $query): int
    {
        return $query
            ->whereRaw("recipient_hash <> encode(sha256(convert_to('anonymized:' || id::text, 'UTF8')), 'hex')")
            ->update(['recipient_hash' => DB::raw("encode(sha256(convert_to('anonymized:' || id::text, 'UTF8')), 'hex')")]);
    }

    /* ------------------------------------------------------------------ cuenta de acceso */

    /** La persona deja de tener acceso a LA organización en contexto (la membresía se conserva, desactivada). */
    public function deactivateMembership(User $user, Organization $organization): void
    {
        $user->organizations()->updateExistingPivot($organization->id, ['status' => 'deactivated', 'deactivated_at' => now()]);
    }

    /**
     * Anonimiza la cuenta: nombre, correo, contraseña (aleatoria: las sesiones abiertas dejan de valer),
     * segundo factor y dispositivos conocidos. Solo para una cuenta que no pertenece a otra organización.
     */
    public function eraseAccount(User $user): void
    {
        // Antes de cambiar el correo: el token de restablecimiento se busca por él.
        Password::broker()->deleteToken($user);
        KnownDevice::query()->where('user_id', $user->id)->delete();

        $user->forceFill([
            'name' => self::NAME,
            'email' => "anonymized-{$user->id}@".self::EMAIL_DOMAIN,
            'password' => Str::random(64),
            'remember_token' => null,
            'email_verified_at' => null,
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'anonymized_at' => now(),
        ])->save();
    }

    /* ------------------------------------------------------------------ auditoría */

    /**
     * Quita el nombre y el correo de la auditoría de la organización (descripción, propiedades y cambios);
     * la entrada se conserva, con su causante y su sujeto, que son identificadores sin datos personales.
     * Si se anonimiza una cuenta, quita además de lo que esa cuenta causó la IP, la huella del correo y la
     * etiqueta del dispositivo (inventario de `docs/privacidad.md`, auditoría de accesos).
     *
     * @param  list<string>  $needles
     * @param  list<string>  $emails  correos de la cuenta anonimizada, si la hubo
     */
    public function redactAuditLog(array $needles, ?string $accountId, array $emails): int
    {
        $count = 0;
        $organizationId = CurrentOrganization::id();

        if ($needles !== []) {
            $count += $this->rewriteAudit(
                Activity::query()->where('organization_id', $organizationId)->where(function ($query) use ($needles): void {
                    foreach ($needles as $needle) {
                        foreach ($this->likePatterns($needle) as $pattern) {
                            $query->orWhereRaw("description ilike ? escape '\\'", [$pattern])
                                ->orWhereRaw("properties::text ilike ? escape '\\'", [$pattern])
                                ->orWhereRaw("attribute_changes::text ilike ? escape '\\'", [$pattern]);
                        }
                    }
                }),
                static fn (mixed $value): mixed => Redactor::deep($value, $needles),
            );
        }

        if ($accountId !== null) {
            $hashes = array_map(AccessThrottle::emailHash(...), $emails);

            $count += $this->rewriteAudit(
                Activity::query()->where(fn ($query) => $query
                    ->where('causer_id', $accountId)
                    ->orWhereIn(DB::raw("properties->>'email_hash'"), $hashes)),
                static function (mixed $value): mixed {
                    if (is_array($value)) {
                        unset($value['ip'], $value['email_hash'], $value['device']);
                    }

                    return $value;
                },
                onlyProperties: true,
            );
        }

        return $count;
    }

    /**
     * @param  Builder<Activity>  $query
     * @param  callable(mixed): mixed  $rewrite
     */
    private function rewriteAudit(Builder $query, callable $rewrite, bool $onlyProperties = false): int
    {
        $count = 0;

        $query->chunkById(200, function ($entries) use ($rewrite, $onlyProperties, &$count): void {
            foreach ($entries as $entry) {
                $values = [];

                foreach (['properties', 'attribute_changes'] as $column) {
                    $raw = $entry->getRawOriginal($column);
                    $decoded = is_string($raw) ? json_decode($raw, true) : null;
                    $rewritten = $decoded === null ? null : $rewrite($decoded);

                    if ($decoded !== null && $rewritten !== $decoded) {
                        $values[$column] = json_encode($rewritten, JSON_THROW_ON_ERROR);
                    }
                }

                if (! $onlyProperties) {
                    $description = (string) $entry->description;
                    $new = $rewrite($description);

                    if ($new !== $description) {
                        $values['description'] = $new;
                    }
                }

                if ($values !== []) {
                    Activity::query()->whereKey($entry->getKey())->update($values);
                    $count++;
                }
            }
        });

        return $count;
    }

    /**
     * La cadena tal cual y como la escribe `json_encode` (acentos como `é`, barras escapadas): el
     * texto JSON de las propiedades usa esa segunda forma.
     *
     * @return list<string>
     */
    private function likePatterns(string $needle): array
    {
        $escape = static fn (string $value): string => '%'.addcslashes($value, '%_\\').'%';
        $encoded = substr((string) json_encode($needle), 1, -1);

        return array_values(array_unique([$escape($needle), $escape($encoded)]));
    }

    private function deleteFrom(Filesystem $disk, string $path): bool
    {
        return $disk->delete($path);
    }
}
