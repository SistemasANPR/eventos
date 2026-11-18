<?php

declare(strict_types=1);

namespace App\Actions\SubmissionExports;

use App\Exceptions\Domain\SubmissionExportTooLarge;
use App\Jobs\ExportSubmissionsJob;
use App\Models\Event;
use App\Models\Submission;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\SubmissionExport\ExportFilters;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pide la exportación de las propuestas de un evento (plan 009, H-009-3, DA-3). SIEMPRE por cola:
 * valida el permiso `submissions.export` en el servidor, valida los filtros, RECALCULA la consulta
 * desde la base (nunca confía en una selección del cliente), rechaza si supera el tope de filas y
 * encola `ExportSubmissionsJob`, que genera el archivo y deja la auditoría.
 *
 * Exige la organización en contexto (la fija el panel por su ruta): un evento de otra organización
 * no existe (404) y se comprueba ANTES de autorizar.
 *
 * @throws ModelNotFoundException el evento no es de la organización en contexto
 * @throws AuthorizationException sin `submissions.export` o sin membresía activa
 * @throws ValidationException filtro o formato inválido (mismo error genérico, CA-009-4)
 * @throws SubmissionExportTooLarge el filtro devuelve más filas que el tope (CA-009-16)
 */
final class ExportSubmissions
{
    use AuthorizesInOrganization;

    /** Tope duro de filas por exportación (DA-3). */
    public const MAX_ROWS = 5000;

    /** @var list<string> */
    public const FORMATS = ['xlsx', 'csv'];

    public function __construct(private readonly int $maxRows = self::MAX_ROWS) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return string identificador de la exportación (nombre del archivo y clave de la auditoría)
     */
    public function handle(Event $event, array $filters, User $actor, string $format = 'xlsx'): string
    {
        $organization = CurrentOrganization::get();
        $event = Event::query()->findOrFail($event->id);

        $this->authorizeActor($actor, 'export', Submission::class);

        if (! in_array($format, self::FORMATS, true)) {
            throw ValidationException::withMessages(['format' => __('submission_panel.export.format_invalid')]);
        }

        $validated = ExportFilters::fromArray($filters, $event);
        $rows = $validated->query()->count();

        if ($rows > $this->maxRows) {
            throw new SubmissionExportTooLarge($rows, $this->maxRows);
        }

        $exportId = (string) Str::uuid();

        ExportSubmissionsJob::dispatch($organization->id, $event->id, $actor->id, $validated->toArray(), $format, $exportId);

        return $exportId;
    }
}
