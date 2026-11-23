<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Actions;

use App\Actions\Reviews\AssignReviewers;
use App\Actions\Reviews\RemoveReviewAssignment;
use App\Enums\EventAssignmentRole;
use App\Enums\ReviewAssignmentRemoval;
use App\Enums\ReviewAssignmentScope;
use App\Enums\ReviewAssignmentStatus;
use App\Filament\Support\ActionErrors;
use App\Http\Requests\Reviews\AssignReviewersRequest;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\Track;
use App\Support\Reviews\ReviewAssignmentReport;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Asignar y quitar evaluadores desde el panel (plan 011, F3a). Delgadas: las reglas y la autorización viven
 * en `AssignReviewers` y `RemoveReviewAssignment`; aquí solo se oculta lo que no procede (ocultar no es
 * autorizar) y se muestra el informe de la operación, que nunca omite en silencio (CA-011-41).
 */
final class ReviewAssignmentActions
{
    /** Máximo de omisiones que el aviso lista una por una; el resto se resume en una cifra. */
    private const MAX_LISTED = 10;

    /** «Asignar evaluadores» sobre las propuestas marcadas en la lista (CA-011-39): sin regla de alcance. */
    public static function bulkAssign(): BulkAction
    {
        return BulkAction::make('assignReviewers')
            ->label(__('review_assignments.actions.assign'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalHeading(__('review_assignments.actions.assign'))
            ->modalDescription(__('review_assignments.actions.assign_description'))
            ->modalSubmitActionLabel(__('review_assignments.actions.assign'))
            ->schema(fn ($livewire): array => [self::reviewersSelect(self::event($livewire))])
            ->visible(fn ($livewire): bool => self::canAssign(self::event($livewire)))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data, $livewire): void {
                self::assign(self::event($livewire), [
                    'scope' => ReviewAssignmentScope::Submission->value,
                    'submission_ids' => array_values(array_map(strval(...), $records->modelKeys())),
                    'reviewer_ids' => $data['reviewer_ids'] ?? [],
                ]);
            });
    }

    /** «Asignar por eje o convocatoria»: expansión única sobre las propuestas elegibles de ese alcance. */
    public static function assignByScope(): Action
    {
        return Action::make('assignReviewersByScope')
            ->label(__('review_assignments.actions.assign_by_scope'))
            ->icon(Heroicon::OutlinedUserGroup)
            ->color('gray')
            ->modalHeading(__('review_assignments.actions.assign_by_scope'))
            ->modalDescription(__('review_assignments.actions.assign_by_scope_description'))
            ->modalSubmitActionLabel(__('review_assignments.actions.assign'))
            ->schema(fn ($livewire): array => [
                Select::make('scope')
                    ->label(__('review_assignments.form.scope'))
                    ->options([
                        ReviewAssignmentScope::Track->value => __('review_assignments.form.scope_track'),
                        ReviewAssignmentScope::Call->value => __('review_assignments.form.scope_call'),
                    ])
                    ->required()
                    ->live()
                    ->native(false),
                Select::make('track_id')
                    ->label(__('review_assignments.form.track'))
                    ->options(self::trackOptions(self::event($livewire)))
                    ->visible(fn (Get $get): bool => $get('scope') === ReviewAssignmentScope::Track->value)
                    ->required(fn (Get $get): bool => $get('scope') === ReviewAssignmentScope::Track->value)
                    ->native(false),
                Select::make('call_id')
                    ->label(__('review_assignments.form.call'))
                    ->options(self::callOptions(self::event($livewire)))
                    ->visible(fn (Get $get): bool => $get('scope') === ReviewAssignmentScope::Call->value)
                    ->required(fn (Get $get): bool => $get('scope') === ReviewAssignmentScope::Call->value)
                    ->native(false),
                self::reviewersSelect(self::event($livewire)),
            ])
            ->visible(fn ($livewire): bool => self::canAssign(self::event($livewire)))
            ->action(function (array $data, $livewire): void {
                // Solo los campos del alcance elegido: el otro llega nulo y no debe validarse como un UUID.
                self::assign(self::event($livewire), array_filter([
                    'scope' => $data['scope'] ?? null,
                    'track_id' => $data['track_id'] ?? null,
                    'call_id' => $data['call_id'] ?? null,
                    'reviewer_ids' => $data['reviewer_ids'] ?? [],
                ], static fn (mixed $value): bool => $value !== null));
            });
    }

    /** «Quitar evaluador» desde el detalle de una propuesta: elimina la pendiente o cancela la que ya empezó. */
    public static function remove(): Action
    {
        return Action::make('removeReviewer')
            ->label(__('review_assignments.actions.remove'))
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('gray')
            ->modalDescription(__('review_assignments.actions.remove_description'))
            ->modalSubmitActionLabel(__('review_assignments.actions.remove'))
            ->schema(fn (Submission $record): array => [
                Select::make('assignment_id')
                    ->label(__('review_assignments.form.assignment'))
                    ->options(self::removableOptions($record))
                    ->required()
                    ->native(false),
            ])
            ->visible(fn (Submission $record): bool => Gate::allows('create', [ReviewAssignment::class, self::eventOf($record)])
                && self::removableOptions($record) !== [])
            ->action(function (Submission $record, array $data): void {
                try {
                    $outcome = app(RemoveReviewAssignment::class)->handle(ActionErrors::actor(), self::eventOf($record), [
                        'assignment_id' => $data['assignment_id'] ?? null,
                    ]);
                } catch (ValidationException $exception) {
                    self::fail(ActionErrors::summary($exception));

                    return;
                }

                Notification::make()
                    ->title(__($outcome === ReviewAssignmentRemoval::Deleted
                        ? 'review_assignments.removal.deleted'
                        : 'review_assignments.removal.cancelled'))
                    ->success()
                    ->send();
            });
    }

    /** @param  array<string, mixed>  $data */
    private static function assign(Event $event, array $data): void
    {
        try {
            $report = app(AssignReviewers::class)->handle(ActionErrors::actor(), $event, $data);
        } catch (ValidationException $exception) {
            self::fail(ActionErrors::summary($exception));

            return;
        }

        self::report($report);
    }

    /**
     * Muestra el resultado: lo asignado, lo omitido con su motivo (cada pareja en conflicto) y los avisos
     * que no se pudieron encolar. Persistente: es el único lugar donde se ve el informe.
     */
    private static function report(ReviewAssignmentReport $report): void
    {
        $lines = [__('review_assignments.report.assigned', ['count' => $report->assignedCount()])];

        if ($report->skippedCount() > 0) {
            $lines[] = __('review_assignments.report.skipped', ['count' => $report->skippedCount()]);

            foreach ($report->skippedByReason() as $reason => $count) {
                $lines[] = '— '.__("review_assignments.skip_reasons.{$reason}").': '.$count;
            }

            foreach (array_slice($report->skipped, 0, self::MAX_LISTED) as $item) {
                $lines[] = '• '.$item->submissionTitle.($item->reviewerName !== null ? ' ← '.$item->reviewerName : '').' ('.$item->reason->label().')';
            }

            if ($report->skippedCount() > self::MAX_LISTED) {
                $lines[] = __('review_assignments.report.more', ['count' => $report->skippedCount() - self::MAX_LISTED]);
            }
        }

        if ($report->notificationFailures !== []) {
            $lines[] = __('review_assignments.report.notification_failed', ['count' => count($report->notificationFailures)]);
        }

        $notification = Notification::make()
            ->title(__('review_assignments.report.title'))
            // El cuerpo admite HTML: cada línea se escapa porque lleva títulos y nombres que escribieron otras personas.
            ->body(implode('<br>', array_map(static fn (string $line): string => e($line), $lines)))
            ->persistent();

        if ($report->notificationFailures !== [] || $report->assignedCount() === 0) {
            $notification->warning();
        } else {
            $notification->success();
        }
        $notification->send();
    }

    private static function fail(string $message): void
    {
        Notification::make()
            ->title(__('review_assignments.report.failed'))
            ->body($message)
            ->danger()
            ->persistent()
            ->send();
    }

    private static function canAssign(Event $event): bool
    {
        return Gate::allows('create', [ReviewAssignment::class, $event]);
    }

    /** Evaluadores con asignación de evento de rol `reviewer`: solo ellos pueden recibir propuestas (CA-011-10). */
    private static function reviewersSelect(Event $event): Select
    {
        return Select::make('reviewer_ids')
            ->label(__('review_assignments.form.reviewers'))
            ->options(self::reviewerOptions($event))
            ->multiple()
            ->required()
            ->maxItems(AssignReviewersRequest::MAX_REVIEWERS)
            ->searchable()
            ->native(false)
            ->helperText(self::reviewerOptions($event) === [] ? __('review_assignments.form.no_reviewers') : null);
    }

    /** @return array<string, string> */
    private static function reviewerOptions(Event $event): array
    {
        return EventAssignment::query()
            ->where('event_id', $event->id)
            ->where('role', EventAssignmentRole::Reviewer)
            ->with('user')
            ->get()
            ->mapWithKeys(fn (EventAssignment $assignment): array => [
                $assignment->user_id => $assignment->user->name.' ('.$assignment->user->email.')',
            ])
            ->sort()
            ->all();
    }

    /** @return array<string, string> */
    private static function trackOptions(Event $event): array
    {
        return Track::query()->where('event_id', $event->id)->orderBy('position')->get()
            ->mapWithKeys(fn (Track $track): array => [$track->id => (string) $track->getTranslation('name', app()->getLocale(), true)])
            ->all();
    }

    /** @return array<string, string> */
    private static function callOptions(Event $event): array
    {
        return CallForSubmissions::query()->where('event_id', $event->id)->get()
            ->mapWithKeys(fn (CallForSubmissions $call): array => [$call->id => (string) $call->getTranslation('title', app()->getLocale(), true)])
            ->all();
    }

    /**
     * Las asignaciones que se pueden quitar de la propuesta (las ya canceladas no).
     *
     * @return array<string, string>
     */
    private static function removableOptions(Submission $submission): array
    {
        return ReviewAssignment::query()
            ->where('submission_id', $submission->id)
            ->where('status', '!=', ReviewAssignmentStatus::Cancelled)
            ->with('reviewer')
            ->get()
            ->mapWithKeys(fn (ReviewAssignment $assignment): array => [
                $assignment->id => $assignment->reviewer->name.' — '.$assignment->status->getLabel(),
            ])
            ->all();
    }

    private static function event(mixed $livewire): Event
    {
        $event = is_object($livewire) && method_exists($livewire, 'getOwnerRecord') ? $livewire->getOwnerRecord() : null;

        return $event instanceof Event ? $event : throw new LogicException('Asignar evaluadores necesita un evento (el dueño de la página).');
    }

    private static function eventOf(Submission $submission): Event
    {
        return Event::query()->findOrFail($submission->event_id);
    }
}
