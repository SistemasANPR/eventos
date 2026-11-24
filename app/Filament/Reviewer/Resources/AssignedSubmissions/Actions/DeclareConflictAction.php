<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Resources\AssignedSubmissions\Actions;

use App\Actions\Reviews\DeclareConflictOfInterest;
use App\Filament\Reviewer\Resources\AssignedSubmissions\AssignedSubmissionResource;
use App\Filament\Support\ActionErrors;
use App\Models\Review;
use App\Models\ReviewAssignment;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Botón «Declarar conflicto» del evaluador (plan 011, CA-011-17), el mismo en el tablero y en el detalle.
 * Pide confirmación porque la declaración no se deshace desde el panel. Ocultarlo no autoriza nada: la Action
 * `DeclareConflictOfInterest` vuelve a comprobar la Policy y exige la confirmación.
 */
final class DeclareConflictAction
{
    public static function make(): Action
    {
        return Action::make('declareConflict')
            ->label(__('reviewer_integrity.conflict.action'))
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('reviewer_integrity.conflict.heading'))
            ->modalDescription(__('reviewer_integrity.conflict.description'))
            ->modalSubmitActionLabel(__('reviewer_integrity.conflict.confirm'))
            ->visible(fn (ReviewAssignment $record): bool => Gate::allows('declareConflict', [Review::class, $record]))
            ->action(function (ReviewAssignment $record, Action $action): void {
                try {
                    app(DeclareConflictOfInterest::class)->handle(ActionErrors::actor(), $record, ['confirmed' => true]);
                } catch (AuthorizationException) {
                    // La asignación cambió mientras la pantalla estaba abierta (enviada, cancelada, ya declarada).
                    Notification::make()->danger()->title(__('reviewer_integrity.conflict.not_allowed'))->persistent()->send();
                    $action->failure();

                    return;
                }

                Notification::make()->success()->title(__('reviewer_integrity.conflict.declared'))->send();
            })
            ->successRedirectUrl(fn (): string => AssignedSubmissionResource::getUrl('index'));
    }
}
