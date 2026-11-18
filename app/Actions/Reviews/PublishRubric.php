<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\RubricStatus;
use App\Http\Requests\Reviews\PublishRubricRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Events\OfferedLocaleTexts;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Publica una rúbrica en borrador (plan 011, H-011-1): revalida su contenido
 * guardado con las reglas de publicación y la deja lista para evaluar. Se
 * ejecuta en la organización DE LA RÚBRICA. Queda auditado con el estado
 * anterior y el nuevo.
 *
 * @throws AuthorizationException si quien publica no tiene `rubrics.manage` en la organización de la rúbrica
 * @throws ValidationException si no es un borrador o su contenido incumple las reglas de publicación
 */
final class PublishRubric
{
    use AuthorizesInOrganization;

    public function handle(User $actor, Rubric $rubric): Rubric
    {
        $organization = Organization::query()->findOrFail($rubric->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $rubric): Rubric {
            $this->authorizeActor($actor, 'publish', $rubric);

            return DB::transaction(function () use ($actor, $rubric): Rubric {
                $current = Rubric::query()->whereKey($rubric->id)->lockForUpdate()->firstOrFail();

                if ($current->status !== RubricStatus::Draft) {
                    throw ValidationException::withMessages(['status' => __('reviews.only_draft_can_be_published')]);
                }

                $event = Event::query()->findOrFail($current->event_id);
                $content = $this->content($current);
                // Un idioma que el evento dejó de ofrecer no se valida: su texto queda guardado pero oculto (plan 025).
                $content['criteria'] = array_values(OfferedLocaleTexts::discardInEach($content['criteria'], 'label', $event));
                Validator::make($content, PublishRubricRequest::rulesFor($event, $content['criteria']))->validate();

                $current->forceFill(['status' => RubricStatus::Published])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($current)
                    ->event('rubric_published')
                    ->withProperties([
                        'organization_id' => $current->organization_id,
                        'event_id' => $current->event_id,
                        'version' => $current->version,
                        'old' => ['status' => RubricStatus::Draft->value],
                        'new' => ['status' => RubricStatus::Published->value],
                    ])
                    ->log('rubric_published');

                return $current;
            });
        });
    }

    /**
     * El contenido guardado con la forma que esperan las reglas.
     *
     * @return array{submission_type_id: string, blind_review: bool, min_reviewers: int, discrepancy_threshold: int, criteria: list<array<string, mixed>>}
     */
    private function content(Rubric $rubric): array
    {
        return [
            'submission_type_id' => $rubric->submission_type_id,
            'blind_review' => $rubric->blind_review,
            'min_reviewers' => $rubric->min_reviewers,
            'discrepancy_threshold' => $rubric->discrepancy_threshold,
            'criteria' => $rubric->criteria()->get()->map(static fn (RubricCriterion $criterion): array => [
                'label' => $criterion->getTranslations('label'),
                'kind' => $criterion->kind->value,
                'scale_min' => $criterion->scale_min,
                'scale_max' => $criterion->scale_max,
                'weight' => $criterion->weight,
            ])->all(),
        ];
    }
}
