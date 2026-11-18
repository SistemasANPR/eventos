<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Autoriza a quien ejecuta una Action con las Policies, DENTRO del contexto de
 * organización que la Action ya estableció. Los roles se cachean en el modelo
 * por *team*: se recargan para no leer los de otra organización.
 */
trait AuthorizesInOrganization
{
    /**
     * `$subject` es el modelo (o su clase) sobre el que se actúa. Si la Policy
     * recibe además otro modelo (p. ej. crear un aviso exige su evento), va en `$related`.
     *
     *
     * @throws AuthorizationException
     */
    private function authorizeActor(User $actor, string $ability, string|object $subject, ?object $related = null): void
    {
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        Gate::forUser($actor)->authorize($ability, $related === null ? $subject : [$subject, $related]);
    }
}
