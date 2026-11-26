<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Exceptions\Domain\PersonDataNotFound;
use App\Http\Requests\ExportPersonDataRequest;
use App\Models\Contact;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Files\PrivateUploads;
use App\Support\Privacy\PersonDataExport;
use App\Support\Privacy\PersonDataSnapshot;
use App\Support\Privacy\PersonIdentity;
use App\Support\Privacy\PersonRecords;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\TenantStoragePath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

/**
 * Exporta todo lo que la organización en contexto guarda de una persona (plan 014, H-014-4, CA-014-20), para
 * atender su derecho de acceso. La localiza por su correo en TODOS los módulos del inventario
 * (`PersonRecords`) y genera un archivo JSON en el disco PRIVADO, con nombre generado
 * (`organizations/{org}/exports/person-{uuid}.json`; la tarea `submission-exports:purge` borra todo lo de
 * `exports/` a las 24 horas) y devuelve un enlace temporal firmado que solo sirve a quien lo pidió. La
 * auditoría guarda quién, cuándo, el motivo (lista cerrada) y los totales, nunca el contenido ni el correo.
 *
 * Orden de los errores: 403 antes que validación; si no hay datos de esa persona en LA organización, 404.
 *
 * @throws AuthorizationException sin `personal_data.manage` o sin membresía activa
 * @throws ValidationException correo o motivo inválidos
 * @throws PersonDataNotFound no hay datos de esa persona en la organización
 */
final class ExportPersonData
{
    use AuthorizesInOrganization;

    /** Vigencia del enlace firmado. */
    public const URL_MINUTES = 10;

    public function __construct(private readonly PrivateUploads $uploads) {}

    /** @param  array<string, mixed>  $data  `email` y `reason` */
    public function handle(User $actor, array $data): PersonDataExport
    {
        $organization = CurrentOrganization::get();

        $this->authorizeActor($actor, 'export', Contact::class);

        /** @var array{email: string, reason: string} $validated */
        $validated = Validator::make(
            is_string($data['email'] ?? null) ? [...$data, 'email' => PersonIdentity::normalize($data['email'])] : $data,
            ExportPersonDataRequest::fieldRules(),
        )->validate();

        $records = PersonRecords::find($validated['email']);

        if ($records->isEmpty()) {
            throw new PersonDataNotFound;
        }

        $snapshot = PersonDataSnapshot::build($records);
        $exportId = (string) Str::uuid();

        try {
            $written = $this->uploads->disk()->put(self::pathFor($exportId), json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['visibility' => 'private']);
        } catch (JsonException $exception) {
            throw new RuntimeException('No se pudo serializar la exportación de la persona.', previous: $exception);
        }

        if (! $written) {
            throw new RuntimeException("No se pudo guardar la exportación en el disco '{$this->uploads->diskName()}'.");
        }

        $counts = [
            'contacts' => count($snapshot['contacts']),
            'submissions' => count($snapshot['submissions']),
            'speakers' => count($snapshot['speakers']),
            'activity_contacts' => count($snapshot['activity_contacts']),
            'emails_sent' => count($snapshot['emails_sent']),
        ];

        activity()
            ->causedBy($actor)
            ->event('person_data_exported')
            ->withProperties([
                'organization_id' => $organization->id,
                'export_id' => $exportId,
                'reason' => $validated['reason'],
                'counts' => $counts,
            ])
            ->log('person_data_exported');

        $expiresAt = now()->addMinutes(self::URL_MINUTES);

        return new PersonDataExport(
            id: $exportId,
            url: URL::temporarySignedRoute('person-data-exports.download', $expiresAt, [
                'organization' => $organization->slug,
                'export' => $exportId,
                'user' => $actor->id,
            ]),
            expiresAt: $expiresAt->toImmutable(),
            counts: $counts,
        );
    }

    /** Ruta de la exportación en el disco privado, dentro de la organización en contexto. */
    public static function pathFor(string $exportId): string
    {
        return TenantStoragePath::for("exports/person-{$exportId}.json");
    }
}
