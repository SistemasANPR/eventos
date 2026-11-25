<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Enums\RetentionCategory;
use App\Http\Requests\SetRetentionPoliciesRequest;
use App\Models\RetentionPolicy;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Fija los plazos de retención de la organización en contexto (plan 014, H-014-6, CA-014-35). Recibe, por
 * categoría, los días o nada (sin plazo: se quita el que hubiera). Solo afecta a SU organización y solo a las
 * categorías que vienen en `$data`: una categoría ausente no cambia. No hay valores por defecto (`[PENDIENTE:
 * plazos]`): una organización que nunca los fija no pierde nada. Audita `retention_policies_updated` con los
 * plazos resultantes.
 *
 * @throws AuthorizationException sin `retention.manage` o sin membresía activa
 * @throws ValidationException un plazo que no es un entero entre 1 y 36500
 */
final class SetRetentionPolicies
{
    use AuthorizesInOrganization;

    /**
     * @param  array<string, mixed>  $data  `RetentionCategory::value` => días (o nulo / vacío para quitar el plazo)
     * @return array<string, int|null> los plazos resultantes de las categorías tocadas
     */
    public function handle(User $actor, array $data): array
    {
        $organization = CurrentOrganization::get();

        $this->authorizeActor($actor, 'manage', RetentionPolicy::class);

        $submitted = array_intersect_key($data, array_flip(array_map(fn (RetentionCategory $category): string => $category->value, RetentionCategory::cases())));
        $submitted = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $submitted);

        $valid = Validator::make($submitted, array_intersect_key(SetRetentionPoliciesRequest::fieldRules(), $submitted))->validate();

        /** @var array<string, int|null> $result */
        $result = [];

        DB::transaction(function () use ($valid, $organization, $actor, &$result): void {
            foreach ($valid as $category => $days) {
                $existing = RetentionPolicy::query()->where('category', $category)->first();

                if ($days === null) {
                    $existing?->delete();
                    $result[$category] = null;

                    continue;
                }

                $days = (int) $days;

                if ($existing === null) {
                    RetentionPolicy::query()->forceCreate(['category' => $category, 'retention_days' => $days]);
                } elseif ($existing->retention_days !== $days) {
                    $existing->forceFill(['retention_days' => $days])->save();
                }

                $result[$category] = $days;
            }

            activity()
                ->causedBy($actor)
                ->event('retention_policies_updated')
                ->withProperties(['organization_id' => $organization->id, 'policies' => $result])
                ->log('retention_policies_updated');
        });

        return $result;
    }
}
