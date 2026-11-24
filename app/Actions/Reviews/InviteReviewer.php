<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Actions\Events\AssignUserToEvent;
use App\Actions\Notifications\SendNotification;
use App\Enums\EventAssignmentRole;
use App\Enums\OrganizationRole;
use App\Enums\ReviewerInvitationOutcome;
use App\Http\Requests\Reviews\InviteReviewerRequest;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AccessThrottle;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Auth\OrganizationAdmins;
use App\Support\Auth\TeamInvitationLimiter;
use App\Support\Auth\TwoFactorRequirement;
use App\Support\Notifications\MailRecipient;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

/**
 * Invita a un evaluador a un evento (plan 011, H-011-8; CA-011-33 a 37). Se ejecuta en la organización DEL EVENTO.
 *
 * - Autoriza con `reviewers.invite` (`EventAssignmentPolicy::inviteReviewer`), NO con `event_assignments.manage`:
 *   el organizador invita aunque no administre las asignaciones de evento. Por eso usa `AssignUserToEvent::record`.
 * - Con la 2FA obligatoria apagada en el entorno (`TwoFactorRequirement::isEnforced()`), rechaza antes de crear
 *   nada: sin segundo factor no se da acceso a datos de autores a una persona externa (CA-011-34).
 * - Correo nuevo, o de una cuenta que existe fuera de esta organización: la une a la organización con el rol
 *   `reviewer`, le asigna el evento con rol de evento `reviewer` y le encola `reviewer.invitation` por
 *   `SendNotification`. Una cuenta nueva lleva un enlace para establecer su contraseña (mismo mecanismo y
 *   caducidad que `team.set_password`); una existente, el enlace de acceso: nunca se emite un token de
 *   contraseña para una cuenta que ya tenía dueño.
 * - Correo que ya es integrante de la organización: no se duplica la cuenta, se añade la asignación de evento
 *   si faltaba y NO se envía ningún enlace (CA-011-36); devuelve `AlreadyMember`.
 * - Integrante desactivado: se rechaza; la reactivación confirma el rol (plan 003).
 * - No revela si el correo existía fuera de la organización: ambos casos devuelven `Invited` y se igualan en
 *   duración con `Timebox`. Que ya sea integrante de ESTA organización sí se informa (dato propio).
 * - Límite de 10 invitaciones por minuto por usuario, compartido con el equipo (`TeamInvitationLimiter`).
 * - Todo en una transacción; el correo se encola DESPUÉS de confirmarla. Si encolarlo falla, la excepción se
 *   propaga (el fallo se ve) y la cuenta y la asignación persisten: reenviar es la deuda DT-21.
 * - Auditoría: `reviewer_invited` (sin el correo) y `event_assignment_created`.
 *
 * Datos de entrada (`InviteReviewerRequest`): `name` y `email`.
 *
 * @throws AuthorizationException si quien invita no tiene `reviewers.invite` en la organización del evento
 * @throws ThrottleRequestsException si superó el límite por minuto (429)
 * @throws ValidationException si la 2FA obligatoria no está activa, el correo es inválido o el integrante está desactivado
 */
final class InviteReviewer
{
    use AuthorizesInOrganization;

    public function __construct(
        private readonly AssignUserToEvent $assignToEvent,
        private readonly SendNotification $notify,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function handle(User $actor, Event $event, array $input): ReviewerInvitationOutcome
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $organization, $input): ReviewerInvitationOutcome {
            $this->authorizeActor($actor, 'inviteReviewer', EventAssignment::class, $event);

            if (! TwoFactorRequirement::isEnforced()) {
                throw ValidationException::withMessages(['email' => __('reviewer_invitation.errors.two_factor_required')]);
            }

            TeamInvitationLimiter::hit($actor);

            $data = $this->validated($input);
            $event = Event::query()->findOrFail($event->id);

            /** @var array{0: User, 1: ReviewerInvitationOutcome, 2: ?string, 3: ?string} $result */
            $result = app(Timebox::class)->call(function () use ($actor, $organization, $event, $data): array {
                [$member, $outcome, $assignmentId, $isNew] = DB::transaction(fn (): array => $this->invite($actor, $organization, $event, $data));

                return [$member, $outcome, $assignmentId, $outcome === ReviewerInvitationOutcome::Invited ? $this->accessUrl($member, $isNew) : null];
            }, (int) config('auth.timebox_duration', 200_000));

            [$member, $outcome, $assignmentId, $accessUrl] = $result;

            if ($outcome === ReviewerInvitationOutcome::Invited && $assignmentId !== null && $accessUrl !== null) {
                $this->sendInvitation($organization, $event, $member, $assignmentId, $accessUrl);
            }

            return $outcome;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, email: string}
     */
    private function validated(array $input): array
    {
        // Postgres distingue mayúsculas y el restablecimiento busca en minúsculas.
        if (is_string($input['email'] ?? null)) {
            $input['email'] = AccessThrottle::normalizeEmail($input['email']);
        }

        /** @var array{name: string, email: string} */
        return Validator::make($input, InviteReviewerRequest::fieldRules())->validate();
    }

    /**
     * @param  array{name: string, email: string}  $data
     * @return array{0: User, 1: ReviewerInvitationOutcome, 2: ?string, 3: bool} el usuario, el resultado, la asignación de evento nueva (si la hubo) y si la cuenta se creó ahora
     */
    private function invite(User $actor, Organization $organization, Event $event, array $data): array
    {
        // Serializa las altas de la organización: dos invitaciones del mismo correo no se pisan.
        OrganizationAdmins::lock($organization);

        $member = User::query()->where('email', $data['email'])->first();

        if ($member !== null && $member->hasDeactivatedMembershipIn($organization)) {
            throw ValidationException::withMessages(['email' => __('team.invite.deactivated_member')]);
        }

        if ($member !== null && $organization->users()->whereKey($member->id)->exists()) {
            $this->ensureEventAssignment($actor, $event, $member);

            return [$member, ReviewerInvitationOutcome::AlreadyMember, null, false];
        }

        $isNew = $member === null;

        // Una cuenta nueva recibe una contraseña aleatoria que nadie conoce: entra con el enlace.
        $member ??= User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Str::random(64),
        ]);

        $organization->users()->attach($member->id, ['status' => 'active']);
        $member->assignRole(OrganizationRole::Reviewer->value);

        $assignment = $this->assignToEvent->record($actor, $event, $member, EventAssignmentRole::Reviewer);

        activity()
            ->causedBy($actor)
            ->performedOn($member)
            ->event('reviewer_invited')
            ->withProperties(['organization_id' => $organization->id, 'event_id' => $event->id, 'new_account' => $isNew])
            ->log('reviewer_invited');

        return [$member, ReviewerInvitationOutcome::Invited, $assignment->id, $isNew];
    }

    /** Añade la asignación de evento de evaluador si el integrante aún no la tiene; si ya la tiene, no hace nada. */
    private function ensureEventAssignment(User $actor, Event $event, User $member): void
    {
        $exists = EventAssignment::query()
            ->where('event_id', $event->id)
            ->where('user_id', $member->id)
            ->where('role', EventAssignmentRole::Reviewer)
            ->exists();

        if (! $exists) {
            $this->assignToEvent->record($actor, $event, $member, EventAssignmentRole::Reviewer);
        }
    }

    /** Cuenta nueva: enlace para establecer la contraseña (60 minutos, como `team.set_password`); existente: acceso. */
    private function accessUrl(User $member, bool $isNew): string
    {
        $panel = Filament::getPanel('admin');

        if (! $isNew) {
            return (string) $panel->getLoginUrl();
        }

        $token = Password::broker($panel->getAuthPasswordBroker())->createToken($member);

        return $panel->getResetPasswordUrl($token, $member);
    }

    private function sendInvitation(Organization $organization, Event $event, User $member, string $assignmentId, string $accessUrl): void
    {
        $this->notify->handle(
            key: 'reviewer.invitation',
            recipient: new MailRecipient($member->email, $member->name, $event->default_locale),
            variables: [
                'reviewer_name' => $member->name,
                'organization_name' => $organization->name,
                'event_name' => $event->getTranslation('name', $event->default_locale),
                'access_url' => $accessUrl,
                'expires_in_minutes' => (string) config('auth.passwords.users.expire'),
            ],
            factId: $assignmentId,
            organization: $organization,
            eventId: $event->id,
        );
    }
}
