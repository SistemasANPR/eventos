<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Http\Requests\InviteTeamMemberRequest;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\MembershipAddedNotification;
use App\Notifications\SetPasswordNotification;
use App\Support\Auth\AccessThrottle;
use App\Support\Auth\OrganizationAdmins;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

/**
 * Da de alta a una persona en el equipo de una organización con un rol
 * (plan 003, H-003-3; CA-003-21 a -23, -28 a -30).
 *
 * - Correo nuevo: crea el usuario con una contraseña aleatoria que nadie
 *   conoce y encola `team.set_password`.
 * - Correo de un usuario que ya existe (por ejemplo, de otra organización):
 *   solo agrega la membresía y el rol, no copia ni cambia sus datos y encola
 *   `team.membership_added` (DA-5).
 *
 * No devuelve nada: la respuesta al administrador es idéntica en ambos casos
 * y la parte que difiere se iguala en duración con `Timebox`, para no revelar
 * si el correo existía en otra organización. Que el correo ya sea integrante
 * de ESTA organización sí se informa (es dato de la propia organización).
 *
 * Autoriza con `team.manage` (Policy), limita a 10 altas por minuto por
 * usuario (DA-7, cada intento autorizado cuenta) y escribe todo en una
 * transacción; el correo se encola después de confirmarla.
 *
 * @param  array<string, mixed>  $input  `name`, `email` y `role` (ver `InviteTeamMemberRequest`)
 *
 * @throws AuthorizationException si quien da el alta no puede gestionar el equipo
 * @throws ThrottleRequestsException si superó el límite de altas por minuto (429)
 * @throws ValidationException si los datos son inválidos o el correo ya es integrante
 */
final class InviteTeamMember
{
    public const MAX_PER_MINUTE = 10;

    /** @param  array<string, mixed>  $input */
    public function handle(User $actor, Organization $organization, array $input): void
    {
        CurrentOrganization::run($organization, function () use ($actor, $organization, $input): void {
            Gate::forUser($actor)->authorize('create', User::class);

            $this->hitLimit($actor);

            $data = $this->validated($input);

            [$member, $isNew] = app(Timebox::class)->call(
                fn (): array => DB::transaction(fn (): array => $this->addMember($actor, $organization, $data)),
                (int) config('auth.timebox_duration', 200_000),
            );

            $member->notify($isNew ? new SetPasswordNotification : new MembershipAddedNotification($organization->name));
        });
    }

    private function hitLimit(User $actor): void
    {
        $key = 'team-invite:'.$actor->id;

        // Se cuenta primero y se decide con el valor de `hit()`: contar después dejaría pasar una ráfaga paralela.
        if (RateLimiter::hit($key, 60) > self::MAX_PER_MINUTE) {
            throw new ThrottleRequestsException(headers: ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, email: string, role: string}
     */
    private function validated(array $input): array
    {
        // Postgres distingue mayúsculas y el restablecimiento busca en minúsculas.
        if (is_string($input['email'] ?? null)) {
            $input['email'] = AccessThrottle::normalizeEmail($input['email']);
        }

        /** @var array{name: string, email: string, role: string} */
        return Validator::make($input, InviteTeamMemberRequest::fieldRules())->validate();
    }

    /**
     * @param  array{name: string, email: string, role: string}  $data
     * @return array{0: User, 1: bool} el usuario y si se creó ahora
     */
    private function addMember(User $actor, Organization $organization, array $data): array
    {
        // Serializa las altas de la organización: dos altas del mismo correo no se pisan.
        OrganizationAdmins::lock($organization);

        $member = User::query()->where('email', $data['email'])->first();
        $isNew = $member === null;

        if ($member !== null && $organization->users()->whereKey($member->id)->exists()) {
            throw ValidationException::withMessages(['email' => __('team.invite.already_member')]);
        }

        // Un usuario nuevo recibe una contraseña aleatoria que nadie conoce: entra con el enlace.
        $member ??= User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Str::random(64),
        ]);

        $organization->users()->attach($member->id, ['status' => 'active']);
        $member->assignRole(OrganizationRole::from($data['role'])->value);

        activity()
            ->causedBy($actor)
            ->performedOn($member)
            ->event('member_invited')
            ->withProperties(['organization_id' => $organization->id, 'role' => $data['role']])
            ->log('member_invited');

        return [$member, $isNew];
    }
}
