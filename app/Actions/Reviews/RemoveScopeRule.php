<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Http\Requests\Reviews\RemoveScopeRuleRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\ReviewScopeRule;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Quita una regla permanente de alcance (plan 011, F3b, DA-6, CA-011-43). Las asignaciones ya creadas se
 * CONSERVAN (no se toca `review_assignments`); las propuestas nuevas dejan de asignarse a ese evaluador por esa
 * regla. Idempotente en el sentido de que una regla ya quitada responde 404. Se ejecuta en la organización DEL
 * EVENTO, autoriza con la Policy antes de buscar la regla y deja auditoría.
 *
 * @throws AuthorizationException si quien quita no tiene `review_assignments.manage` en la organización del evento
 * @throws ValidationException si el identificador no tiene forma de UUID
 * @throws ModelNotFoundException si la regla no es del evento (de otro evento, otra organización, inexistente o ya quitada)
 */
final class RemoveScopeRule
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data): void
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        CurrentOrganization::run($organization, function () use ($actor, $event, $data): bool {
            $this->authorizeActor($actor, 'create', ReviewAssignment::class, $event);

            $input = Validator::make($data, (new RemoveScopeRuleRequest)->rules())->validate();

            DB::transaction(function () use ($actor, $event, $input): void {
                $rule = ReviewScopeRule::query()
                    ->where('event_id', $event->id)
                    ->lockForUpdate()
                    ->findOrFail($input['rule_id']);

                $this->authorizeActor($actor, 'delete', $rule);

                $rule->delete();

                activity()
                    ->causedBy($actor)
                    ->performedOn($rule)
                    ->event('review_scope_rule_removed')
                    ->withProperties([
                        'organization_id' => $event->organization_id,
                        'event_id' => $event->id,
                        'reviewer_id' => $rule->reviewer_id,
                        'scope' => $rule->scope_type->value,
                        'scope_id' => $rule->scopeId(),
                    ])
                    ->log('review_scope_rule_removed');
            });

            return true;
        });
    }
}
