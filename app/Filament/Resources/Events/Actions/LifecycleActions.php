<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Actions;

use App\Actions\Events\ArchiveEvent;
use App\Actions\Events\PublishEvent;
use App\Enums\EventStatus;
use App\Filament\Support\ActionErrors;
use App\Models\Event;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Publicar y archivar un evento desde el panel. Delgadas: la regla de negocio y
 * la autorización viven en las Actions; aquí solo se oculta lo que no procede
 * y se muestra el resultado (ocultar un botón no es autorizar).
 */
final class LifecycleActions
{
    public static function publish(): Action
    {
        return Action::make('publish')
            ->label(__('events.actions.publish'))
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('events.actions.publish_confirm'))
            ->visible(fn (Event $record): bool => $record->status === EventStatus::Draft && Gate::allows('publish', $record))
            ->action(fn (Event $record) => self::run(
                fn () => app(PublishEvent::class)->handle(ActionErrors::actor(), $record),
                __('events.actions.published'),
            ));
    }

    public static function archive(): Action
    {
        return Action::make('archive')
            ->label(__('events.actions.archive'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(__('events.actions.archive_confirm'))
            ->visible(fn (Event $record): bool => $record->status === EventStatus::Published && Gate::allows('archive', $record))
            ->action(fn (Event $record) => self::run(
                fn () => app(ArchiveEvent::class)->handle(ActionErrors::actor(), $record),
                __('events.actions.archived'),
            ));
    }

    /** @param  callable(): mixed  $operation */
    private static function run(callable $operation, string $successTitle): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('events.actions.failed'))
                ->body(ActionErrors::summary($exception))
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
