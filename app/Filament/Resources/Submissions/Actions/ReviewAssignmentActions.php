<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Actions;

use App\Actions\Reviews\AssignReviewers;
use App\Actions\Reviews\InviteReviewer;
use App\Actions\Reviews\RemoveReviewAssignment;
use App\Actions\Reviews\RemoveScopeRule;
use App\Actions\Reviews\SyncScopedAssignments;
use App\Enums\EventAssignmentRole;
use App\Enums\OrganizationRole;
use App\Enums\ReviewAssignmentRemoval;
use App\Enums\ReviewAssignmentScope;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\ReviewerInvitationOutcome;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\ActionErrors;
use App\Http\Requests\Reviews\AssignReviewersRequest;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\ReviewScopeRule;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Support\Reviews\ReviewAssignmentReport;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Asignar, quitar e invitar evaluadores y aplicar las reglas de alcance desde el panel (plan 011, F3a y F3b). Delgadas: las reglas y la autorización viven
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
                    // Por eje o convocatoria se guarda además la regla permanente (DA-6): las propuestas
                    // que lleguen después las asigna `SyncScopedAssignments`, sin correo (DA-8).
                    'save_rule' => true,
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

    /**
     * «Invitar evaluador» (H-011-8): da de alta a una persona como evaluadora del evento y le envía su invitación.
     * Solo con `reviewers.invite`; la 2FA obligatoria y los demás límites los decide `InviteReviewer`.
     */
    public static function invite(): Action
    {
        return Action::make('inviteReviewer')
            ->label(__('review_assignments.invite.action'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->modalHeading(__('review_assignments.invite.action'))
            ->modalDescription(__('review_assignments.invite.description'))
            ->modalSubmitActionLabel(__('review_assignments.invite.submit'))
            ->schema([
                TextInput::make('name')
                    ->label(__('review_assignments.invite.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label(__('review_assignments.invite.email'))
                    ->email()
                    ->required()
                    ->maxLength(255),
            ])
            ->visible(fn ($livewire): bool => Gate::allows('inviteReviewer', [EventAssignment::class, self::event($livewire)]))
            ->action(function (array $data, $livewire): void {
                try {
                    $outcome = app(InviteReviewer::class)->handle(ActionErrors::actor(), self::event($livewire), [
                        'name' => $data['name'] ?? null,
                        'email' => $data['email'] ?? null,
                    ]);
                } catch (ValidationException $exception) {
                    self::fail(ActionErrors::summary($exception));

                    return;
                } catch (ThrottleRequestsException) {
                    self::fail(__('team.invite.throttled'));

                    return;
                }

                Notification::make()
                    ->title(__($outcome === ReviewerInvitationOutcome::Invited
                        ? 'review_assignments.invite.sent'
                        : 'review_assignments.invite.already_member'))
                    ->success()
                    ->send();
            });
    }

    /** «Asignar propuestas nuevas»: aplica ahora las reglas de alcance a lo que quedó sin asignar (DA-6). */
    public static function syncNew(): Action
    {
        return Action::make('syncScopedAssignments')
            ->label(__('review_assignments.actions.sync'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('review_assignments.actions.sync_description'))
            ->visible(fn ($livewire): bool => self::canAssign(self::event($livewire)))
            ->action(function ($livewire): void {
                self::report(app(SyncScopedAssignments::class)->handle(ActionErrors::actor(), self::event($livewire)));
            });
    }

    /** «Quitar regla» de la lista de reglas: las asignaciones ya hechas se conservan (CA-011-43). */
    public static function removeRule(): Action
    {
        return Action::make('removeScopeRule')
            ->label(__('review_assignments.rules.remove'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('review_assignments.rules.remove_description'))
            ->visible(fn (ReviewScopeRule $record): bool => Gate::allows('delete', $record))
            ->action(function (ReviewScopeRule $record, $livewire): void {
                app(RemoveScopeRule::class)->handle(ActionErrors::actor(), self::event($livewire), ['rule_id' => $record->id]);

                Notification::make()->title(__('review_assignments.rules.removed'))->success()->send();
            });
    }

    /** @param  array<string, mixed>  $data */
    private static function assign(Event $event, array $data): void
    {
        // Un evaluador sin asignación de evento no recibe nada hasta que exista (CA-011-49): se ofrece invitarlo.
        $missing = self::reviewersWithoutEventAssignment($event, is_array($data['reviewer_ids'] ?? null) ? array_values(array_map(strval(...), $data['reviewer_ids'])) : []);

        if ($missing !== []) {
            self::offerInvitation($event, $missing);

            return;
        }

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

        if ($report->rulesCreated > 0) {
            $lines[] = __('review_assignments.report.rules_created', ['count' => $report->rulesCreated]);
        }

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

    /**
     * Los evaluadores del evento y, marcados, los integrantes con rol de evaluador que aún no tienen asignación
     * de evento: elegir uno de estos último no asigna nada y ofrece invitarlo (CA-011-49).
     *
     * @return array<string, string>
     */
    private static function reviewerOptions(Event $event): array
    {
        $assigned = EventAssignment::query()
            ->where('event_id', $event->id)
            ->where('role', EventAssignmentRole::Reviewer)
            ->with('user')
            ->get()
            ->mapWithKeys(fn (EventAssignment $assignment): array => [
                $assignment->user_id => $assignment->user->name.' ('.$assignment->user->email.')',
            ])
            ->sort()
            ->all();

        $organization = Organization::query()->findOrFail($event->organization_id);
        $unassigned = User::query()
            ->activeMembersOf($organization)
            ->role(OrganizationRole::Reviewer->value)
            ->whereNotIn('id', array_keys($assigned))
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => $user->name.' ('.$user->email.') — '.__('review_assignments.form.not_in_event'),
            ])
            ->sort()
            ->all();

        return $assigned + $unassigned;
    }

    /**
     * Nombres de los evaluadores elegidos que no tienen asignación de evento de rol `reviewer`.
     *
     * @param  list<string>  $reviewerIds
     * @return list<string>
     */
    private static function reviewersWithoutEventAssignment(Event $event, array $reviewerIds): array
    {
        if ($reviewerIds === []) {
            return [];
        }

        $assigned = EventAssignment::query()
            ->where('event_id', $event->id)
            ->where('role', EventAssignmentRole::Reviewer)
            ->whereIn('user_id', $reviewerIds)
            ->pluck('user_id')
            ->all();

        return User::query()
            ->whereIn('id', array_diff($reviewerIds, $assigned))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /** @param  list<string>  $names */
    private static function offerInvitation(Event $event, array $names): void
    {
        Notification::make()
            ->title(__('review_assignments.invite.needed_title'))
            ->body(e(__('review_assignments.invite.needed_body', ['names' => implode(', ', $names)])))
            ->warning()
            ->persistent()
            ->actions([
                Action::make('goToReviewers')
                    ->label(__('review_assignments.invite.go_to_reviewers'))
                    ->url(EventResource::getUrl('reviewers', ['record' => $event]))
                    ->button(),
            ])
            ->send();
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
