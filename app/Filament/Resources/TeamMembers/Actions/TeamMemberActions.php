<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamMembers\Actions;

use App\Actions\Organizations\ChangeMemberRole;
use App\Actions\Organizations\DeactivateMember;
use App\Actions\Organizations\InviteTeamMember;
use App\Actions\Organizations\ReactivateMember;
use App\Actions\Organizations\ResendTeamInvitation;
use App\Enums\OrganizationRole;
use App\Exceptions\Domain\InvitationNotPending;
use App\Exceptions\Domain\LastOrganizationAdmin;
use App\Exceptions\Domain\MemberNotActive;
use App\Exceptions\Domain\MemberNotDeactivated;
use App\Filament\Support\ActionErrors;
use App\Http\Requests\ChangeMemberRoleRequest;
use App\Http\Requests\InviteTeamMemberRequest;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;

/**
 * Alta, reenvío de invitación, cambio de rol y baja de integrantes desde el panel. Delgadas: las
 * reglas y la autorización viven en las Actions y en `MembershipPolicy`; aquí
 * solo se oculta lo que no procede (ocultar no es autorizar) y se muestra el
 * resultado. Los errores de validación de las Actions llegan al campo del
 * formulario con `ActionErrors::forForm()` y la ruta de estado del formulario
 * de la acción (`mountedActions.N.data`, que `Schema::getStatePath()` entrega).
 */
final class TeamMemberActions
{
    public static function invite(): Action
    {
        return Action::make('invite')
            ->label(__('team.invite.action'))
            ->modalSubmitActionLabel(__('team.invite.submit'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->authorize('create')
            ->schema([
                TextInput::make('name')
                    ->label(__('team.resource.name'))
                    ->required()
                    ->rules(InviteTeamMemberRequest::fieldRules()['name']),
                TextInput::make('email')
                    ->label(__('team.resource.email'))
                    ->required()
                    ->rules(InviteTeamMemberRequest::fieldRules()['email']),
                self::roleSelect(),
            ])
            ->action(function (array $data, Schema $schema): void {
                self::run(
                    fn () => app(InviteTeamMember::class)->handle(ActionErrors::actor(), CurrentOrganization::get(), $data),
                    __('team.invite.sent'),
                    $schema,
                );
            });
    }

    public static function changeRole(): Action
    {
        return Action::make('changeRole')
            ->label(__('team.change_role.action'))
            ->icon(Heroicon::OutlinedKey)
            ->authorize('changeRole')
            ->fillForm(fn (User $record): array => ['role' => $record->getRoleNames()->first()])
            ->schema([self::roleSelect()])
            ->action(function (User $record, array $data, Schema $schema): void {
                self::run(
                    fn () => app(ChangeMemberRole::class)->handle(
                        ActionErrors::actor(),
                        CurrentOrganization::get(),
                        $record,
                        OrganizationRole::from(self::roleOf($data)),
                    ),
                    __('team.change_role.done'),
                    $schema,
                );
            });
    }

    public static function resendInvitation(): Action
    {
        return Action::make('resendInvitation')
            ->label(__('team.resend.action'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->authorize('resendInvitation')
            ->requiresConfirmation()
            ->modalDescription(__('team.resend.confirm'))
            ->action(function (User $record): void {
                self::run(
                    fn () => app(ResendTeamInvitation::class)->handle(ActionErrors::actor(), CurrentOrganization::get(), $record),
                    __('team.resend.done'),
                );
            });
    }

    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label(__('team.deactivate.action'))
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->authorize('deactivate')
            ->requiresConfirmation()
            ->modalDescription(__('team.deactivate.confirm'))
            ->action(function (User $record): void {
                self::run(
                    fn () => app(DeactivateMember::class)->handle(ActionErrors::actor(), CurrentOrganization::get(), $record),
                    __('team.deactivate.done'),
                );
            });
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')
            ->label(__('team.reactivate.action'))
            ->modalSubmitActionLabel(__('team.reactivate.submit'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->authorize('reactivate')
            // La desactivación conserva los roles: se precarga el anterior para confirmarlo o cambiarlo (CA-003-33).
            ->fillForm(fn (User $record): array => ['role' => $record->getRoleNames()->first()])
            ->schema([self::roleSelect()])
            ->action(function (User $record, array $data, Schema $schema): void {
                self::run(
                    fn () => app(ReactivateMember::class)->handle(
                        ActionErrors::actor(),
                        CurrentOrganization::get(),
                        $record,
                        self::roleOf($data),
                    ),
                    __('team.reactivate.done'),
                    $schema,
                );
            });
    }

    private static function roleSelect(): Select
    {
        return Select::make('role')
            ->label(__('team.resource.role'))
            ->options(collect(OrganizationRole::cases())->mapWithKeys(
                fn (OrganizationRole $role): array => [$role->value => __("team.roles.{$role->value}")],
            )->all())
            ->required()
            ->rules(ChangeMemberRoleRequest::fieldRules()['role']);
    }

    /** @param  array<string, mixed>  $data */
    private static function roleOf(array $data): string
    {
        return is_string($data['role'] ?? null) ? $data['role'] : '';
    }

    /**
     * Ejecuta la Action de negocio y traduce sus errores esperados a la
     * interfaz. Una falta de autorización (403) y cualquier otro fallo se
     * propagan: no se disfrazan de éxito.
     *
     * @param  Closure(): mixed  $operation
     */
    private static function run(Closure $operation, string $successTitle, ?Schema $schema = null): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            throw $schema === null ? $exception : ActionErrors::forForm($exception, $schema->getStatePath());
        } catch (ThrottleRequestsException) {
            Notification::make()->title(__('team.invite.throttled'))->danger()->send();

            return;
        } catch (LastOrganizationAdmin) {
            Notification::make()->title(__('team.errors.last_admin'))->danger()->send();

            return;
        } catch (InvitationNotPending) {
            Notification::make()->title(__('team.errors.not_pending'))->danger()->send();

            return;
        } catch (MemberNotActive) {
            Notification::make()->title(__('team.errors.not_active'))->danger()->send();

            return;
        } catch (MemberNotDeactivated) {
            Notification::make()->title(__('team.errors.not_deactivated'))->danger()->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
