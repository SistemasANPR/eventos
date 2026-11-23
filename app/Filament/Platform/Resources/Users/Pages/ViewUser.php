<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Users\Pages;

use App\Actions\Auth\ResetTwoFactorByPlatform;
use App\Filament\Platform\Resources\Users\UserResource;
use App\Filament\Support\ActionErrors;
use App\Http\Requests\ResetTwoFactorRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/** Detalle de un usuario: datos de la cuenta y sus membresías, con el reinicio de su 2FA cuando procede. */
class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    /** @return array<int, mixed> */
    protected function getHeaderActions(): array
    {
        return [$this->resetTwoFactorAction()];
    }

    /**
     * Reinicio de la 2FA por la plataforma (plan 014, CA-014-5): solo si no queda otro administrador que
     * pueda hacerlo, y con la verificación fuera de banda registrada.
     */
    private function resetTwoFactorAction(): Action
    {
        return Action::make('resetTwoFactor')
            ->label(__('platform.users.reset_two_factor.action'))
            ->icon(Heroicon::OutlinedShieldExclamation)
            ->color('warning')
            ->modalHeading(__('platform.users.reset_two_factor.action'))
            ->modalDescription(__('platform.users.reset_two_factor.help'))
            ->schema([
                Select::make('verification_channel')
                    ->label(__('platform.users.reset_two_factor.channel'))
                    ->options(collect(ResetTwoFactorRequest::CHANNELS)->mapWithKeys(
                        fn (string $channel): array => [$channel => __("platform.users.reset_two_factor.channels.{$channel}")],
                    )->all())
                    ->rules(ResetTwoFactorRequest::fieldRules()['verification_channel'])
                    ->required(),
                Textarea::make('verification_note')
                    ->label(__('platform.users.reset_two_factor.note'))
                    ->helperText(__('platform.users.reset_two_factor.note_help'))
                    ->rules(ResetTwoFactorRequest::fieldRules()['verification_note'])
                    ->required()
                    ->rows(3),
            ])
            ->visible(fn (User $record): bool => $record->hasConfirmedTwoFactor() && Gate::allows('resetTwoFactorAsPlatform', [User::class, $record]))
            ->action(function (User $record, array $data): void {
                app(ResetTwoFactorByPlatform::class)->handle(ActionErrors::actor(), $record, $data);
                Notification::make()->title(__('platform.users.reset_two_factor.done'))->success()->send();
            });
    }
}
