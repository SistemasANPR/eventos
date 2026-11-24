<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Models\Activity;
use App\Models\Contact;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Localiza el archivo de una exportación de datos de una persona para entregárselo a quien la pidió (plan
 * 014, CA-014-20). Como la descarga de la exportación de propuestas (plan 009): exige la organización en
 * contexto y `personal_data.manage`, y la exportación se identifica por su auditoría, así que solo existe para
 * QUIEN LA PIDIÓ y dentro de su organización. Para cualquier otro, o pasadas 24 horas, es 404. La ruta se
 * reconstruye, nunca se toma de la auditoría.
 *
 * @throws AuthorizationException sin `personal_data.manage`
 * @throws NotFoundHttpException la exportación no existe, es de otro usuario u otra organización, o venció
 */
final class DownloadPersonDataExport
{
    use AuthorizesInOrganization;

    /** Vigencia del archivo; la tarea programada lo borra pasado este plazo. */
    public const FILE_HOURS = 24;

    /** @return array{disk: string, path: string, name: string} */
    public function handle(User $actor, string $exportId): array
    {
        $organizationId = CurrentOrganization::id();

        $this->authorizeActor($actor, 'export', Contact::class);

        $entry = Activity::query()
            ->where('event', 'person_data_exported')
            ->where('causer_id', $actor->id)
            ->where('properties->export_id', $exportId)
            ->where('properties->organization_id', $organizationId)
            ->first();

        if ($entry === null || $entry->created_at === null || $entry->created_at->lte(now()->subHours(self::FILE_HOURS))) {
            throw new NotFoundHttpException;
        }

        $path = ExportPersonData::pathFor($exportId);
        $disk = (string) config('filesystems.private_uploads_disk');

        if (! Storage::disk($disk)->exists($path)) {
            throw new NotFoundHttpException;
        }

        return ['disk' => $disk, 'path' => $path, 'name' => "persona-{$exportId}.json"];
    }
}
