<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Exceptions\Domain\AnonymizationSaltMissing;
use App\Exceptions\Domain\LastOrganizationAdmin;
use App\Exceptions\Domain\PersonCannotBeAnonymized;
use App\Exceptions\Domain\PersonDataNotFound;
use App\Exceptions\Domain\PersonFileDeletionFailed;
use App\Http\Requests\AnonymizePersonRequest;
use App\Models\AnonymizationLog;
use App\Models\Contact;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Privacy\AnonymizationResult;
use App\Support\Privacy\PersonIdentity;
use App\Support\Privacy\PersonRecords;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Anonimiza los datos de una persona en la organización en contexto, a petición de quien tiene
 * `personal_data.manage` (plan 014, H-014-4, DA-4): propuestas y participantes, ponentes, contactos,
 * evaluaciones, envíos de correo, enlaces de acceso, archivos y la auditoría. Los datos se sustituyen por
 * valores no identificables y los archivos se borran; se conservan los agregados (calificaciones, estados,
 * fechas). Deja una huella no reversible en `anonymization_log` y una entrada de auditoría sin datos
 * personales (el motivo es de una lista cerrada). Las reglas de la cuenta de acceso están en `ErasePersonRecords`.
 *
 * Orden de los errores: 403 (sin permiso) antes que validación, y esta antes que buscar: sin confirmación
 * explícita no cambia nada (CA-014-24). Si no hay datos de esa persona en LA organización (incluida una
 * persona que solo existe en otra) responde 404; si ya estaba anonimizada, devuelve el resultado
 * `already_anonymized` sin cambiar nada (idempotente).
 *
 * @throws AuthorizationException sin `personal_data.manage` o sin membresía activa
 * @throws ValidationException falta el motivo o la confirmación, o no coincide
 * @throws PersonDataNotFound no hay datos de esa persona en la organización
 * @throws PersonCannotBeAnonymized cuenta de plataforma o la propia de quien lo pide
 * @throws LastOrganizationAdmin la persona es el último administrador activo
 * @throws PersonFileDeletionFailed el disco no pudo borrar un archivo (la base no cambió; se puede repetir)
 * @throws AnonymizationSaltMissing falta `ANONYMIZATION_SALT`
 */
final class AnonymizePerson
{
    use AuthorizesInOrganization;

    public function __construct(private readonly ErasePersonRecords $erase) {}

    /** @param  array<string, mixed>  $data  `email`, `reason` y `confirmation` (el correo escrito de nuevo) */
    public function handle(User $actor, array $data): AnonymizationResult
    {
        $organization = CurrentOrganization::get();

        $this->authorizeActor($actor, 'anonymize', Contact::class);

        /** @var array{email: string, reason: string, confirmation: string} $validated */
        $validated = Validator::make(
            $this->normalized($data),
            AnonymizePersonRequest::fieldRules(),
        )->validate();

        $records = PersonRecords::find($validated['email']);

        if ($records->isEmpty()) {
            $alreadyDone = AnonymizationLog::query()
                ->where('email_hash', PersonIdentity::organizationHash($validated['email'], $organization->id))
                ->exists();

            return $alreadyDone ? AnonymizationResult::alreadyAnonymized() : throw new PersonDataNotFound;
        }

        return $this->erase->handle($records, $actor, function (AnonymizationResult $result) use ($actor, $organization, $validated): void {
            activity()
                ->causedBy($actor)
                ->event('person_anonymized')
                ->withProperties([
                    'organization_id' => $organization->id,
                    'reason' => $validated['reason'],
                    ...$result->toArray(),
                ])
                ->log('person_anonymized');
        });
    }

    /**
     * El correo y su confirmación se comparan sin distinguir mayúsculas ni espacios en los extremos.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalized(array $data): array
    {
        foreach (['email', 'confirmation'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = PersonIdentity::normalize($data[$field]);
            }
        }

        return $data;
    }
}
