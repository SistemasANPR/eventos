<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\CriterionKind;
use App\Enums\RubricStatus;
use App\Http\Requests\Reviews\StoreRubricRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Calls\CallAttributes;
use App\Support\Events\OfferedLocaleTexts;
use App\Support\Reviews\RubricUsageChecker;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Crea o edita una rúbrica con sus criterios (plan 011, H-011-1, CA-011-1 y
 * CA-011-3). Se ejecuta en la organización DEL EVENTO.
 *
 * - Sin `$rubric`: crea una versión nueva en borrador (la siguiente del evento y tipo).
 * - Con `$rubric` en borrador, o publicada sin evaluaciones: la edita en sitio.
 * - Con `$rubric` publicada que ya tiene evaluaciones (DA-2): no la toca; crea la
 *   versión siguiente en borrador y la anterior queda intacta con sus evaluaciones.
 *
 * Al editar, el tipo de propuesta es el de la rúbrica; el valor recibido se ignora.
 * Deja auditoría.
 *
 * @throws AuthorizationException si quien guarda no tiene `rubrics.manage` en la organización del evento
 * @throws ModelNotFoundException si `$rubric` no es de ese evento (otra organización o evento: 404)
 * @throws ValidationException si los datos incumplen `StoreRubricRequest`
 */
final class SaveRubric
{
    use AuthorizesInOrganization;

    public function __construct(private readonly RubricUsageChecker $usage) {}

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data, ?Rubric $rubric = null): Rubric
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $data, $rubric): Rubric {
            $current = $rubric === null
                ? null
                : Rubric::query()->where('event_id', $event->id)->findOrFail($rubric->id);

            if ($current === null) {
                $this->authorizeActor($actor, 'create', Rubric::class, $event);
            } else {
                $this->authorizeActor($actor, 'update', $current);
                $data['submission_type_id'] = $current->submission_type_id;
            }

            if (is_array($data['criteria'] ?? null)) {
                $data['criteria'] = OfferedLocaleTexts::discardInEach($data['criteria'], 'label', $event);
            }

            $criteria = is_array($data['criteria'] ?? null) ? $data['criteria'] : [];
            $validated = Validator::make($data, StoreRubricRequest::rulesFor($event, $criteria))->validate();

            return DB::transaction(fn (): Rubric => $this->persist($actor, $event, $validated, $current));
        });
    }

    /** @param  array<string, mixed>  $validated */
    private function persist(User $actor, Event $event, array $validated, ?Rubric $current): Rubric
    {
        // Se bloquea el evento para que dos altas simultáneas no tomen la misma versión.
        Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

        if ($current !== null) {
            $current = Rubric::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();
        }

        $versioning = $current === null || ($current->status === RubricStatus::Published && $this->usage->hasRecordedReviews($current));

        if ($versioning) {
            $rubric = new Rubric;
            $rubric->forceFill([
                'event_id' => $event->id,
                'submission_type_id' => $validated['submission_type_id'],
                'version' => 1 + (int) Rubric::query()
                    ->where('event_id', $event->id)->where('submission_type_id', $validated['submission_type_id'])->max('version'),
                'status' => RubricStatus::Draft,
            ]);
        } else {
            $rubric = $current;
            $rubric->criteria()->delete();
        }

        $rubric->forceFill([
            'blind_review' => (bool) ($validated['blind_review'] ?? false),
            'min_reviewers' => (int) $validated['min_reviewers'],
            'discrepancy_threshold' => (int) $validated['discrepancy_threshold'],
        ])->save();

        $this->writeCriteria($rubric, array_values($validated['criteria']));

        $logged = $current === null ? 'rubric_created' : ($versioning ? 'rubric_versioned' : 'rubric_updated');
        activity()
            ->causedBy($actor)
            ->performedOn($rubric)
            ->event($logged)
            ->withProperties([
                'organization_id' => $rubric->organization_id,
                'event_id' => $event->id,
                'version' => $rubric->version,
                'previous_version' => $versioning ? $current?->version : null,
            ])
            ->log($logged);

        return $rubric->load('criteria');
    }

    /** @param  list<array<string, mixed>>  $criteria */
    private function writeCriteria(Rubric $rubric, array $criteria): void
    {
        foreach ($criteria as $index => $input) {
            $kind = CriterionKind::from($input['kind']);

            $criterion = new RubricCriterion;
            $criterion->forceFill([
                'rubric_id' => $rubric->id,
                'position' => $index + 1,
                'label' => CallAttributes::texts($input['label']),
                'kind' => $kind,
                // Un criterio de texto no se califica: la base exige una escala, se deja la predeterminada.
                'scale_min' => $kind === CriterionKind::Scale ? (int) $input['scale_min'] : 1,
                'scale_max' => $kind === CriterionKind::Scale ? (int) $input['scale_max'] : 5,
                'weight' => $input['weight'],
            ])->save();
        }
    }
}
