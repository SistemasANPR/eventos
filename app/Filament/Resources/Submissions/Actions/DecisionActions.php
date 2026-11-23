<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Actions;

use App\Actions\Submissions\AcceptSubmission;
use App\Actions\Submissions\RejectSubmission;
use App\Actions\Submissions\RevertSubmissionDecision;
use App\Actions\Submissions\WaitlistSubmission;
use App\Enums\SubmissionOrigin;
use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\InvalidSubmissionTransition;
use App\Exceptions\Domain\PublishedDecisionNeedsConfirmation;
use App\Exceptions\Domain\SubmissionStatusConflict;
use App\Filament\Support\ActionErrors;
use App\Models\Submission;
use App\Models\SubmissionStatusChange;
use App\Support\Submissions\SubmissionTransitions;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Aceptar, poner en lista de espera, rechazar y revertir una propuesta desde su detalle (plan 012, H-012-1
 * y H-012-5). Delgadas: la regla de negocio y la autorización viven en las Actions; aquí solo se oculta lo
 * que no procede (ocultar no es autorizar) y se muestra el resultado. Cada acción es una petición `POST`
 * de Livewire con CSRF: abrir el detalle (`GET`) nunca cambia un estado (CA-012-7).
 *
 * El estado que se envía a las Actions es el de la propuesta cargada en la pantalla: si otra persona
 * decidió mientras tanto, la Action responde con un conflicto y aquí se recarga el estado vigente.
 */
final class DecisionActions
{
    public static function accept(): Action
    {
        return self::decision('accept', SubmissionStatus::Accepted, Heroicon::OutlinedCheckCircle, 'success')
            ->action(fn (Submission $record, array $data) => self::run(
                $record,
                fn () => app(AcceptSubmission::class)->handle(ActionErrors::actor(), $record, self::comment($data)),
                __('submission_decisions.actions.accepted'),
            ));
    }

    public static function waitlist(): Action
    {
        return self::decision('waitlist', SubmissionStatus::Waitlisted, Heroicon::OutlinedClock, 'warning')
            ->action(fn (Submission $record, array $data) => self::run(
                $record,
                fn () => app(WaitlistSubmission::class)->handle(ActionErrors::actor(), $record, self::comment($data)),
                __('submission_decisions.actions.waitlisted'),
            ));
    }

    public static function reject(): Action
    {
        return self::decision('reject', SubmissionStatus::Rejected, Heroicon::OutlinedXCircle, 'danger')
            ->action(fn (Submission $record, array $data) => self::run(
                $record,
                fn () => app(RejectSubmission::class)->handle(ActionErrors::actor(), $record, self::comment($data)),
                __('submission_decisions.actions.rejected'),
            ));
    }

    public static function revert(): Action
    {
        return Action::make('revert')
            ->label(__('submission_decisions.actions.revert'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->modalDescription(fn (Submission $record): string => $record->results_published_at === null
                ? __('submission_decisions.actions.revert_confirm')
                : __('submission_decisions.actions.revert_confirm').' '.__('submission_decisions.actions.revert_published_warning'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('submission_decisions.actions.reason'))
                    ->maxLength(SubmissionStatusChange::COMMENT_MAX)
                    ->rows(3),
                Checkbox::make('confirm_published')
                    ->label(__('submission_decisions.actions.confirm_published'))
                    ->accepted()
                    ->visible(fn (Submission $record): bool => $record->results_published_at !== null),
            ])
            ->visible(fn (Submission $record): bool => self::isOfCall($record)
                && SubmissionTransitions::isReversal($record->status, SubmissionStatus::UnderReview)
                && Gate::allows('revert', $record))
            ->action(function (Submission $record, array $data): void {
                $notice = '';

                self::run(
                    $record,
                    function () use ($record, $data, &$notice): void {
                        $notice = app(RevertSubmissionDecision::class)->handle(
                            ActionErrors::actor(),
                            $record,
                            self::text($data, 'reason'),
                            (bool) ($data['confirm_published'] ?? false),
                        )->notice();
                    },
                    __('submission_decisions.actions.reverted'),
                    static fn (): string => $notice,
                );
            });
    }

    /** Acción de decisión: visible si la tabla permite el paso y la Policy `decide` lo autoriza; pide el comentario opcional. */
    private static function decision(string $name, SubmissionStatus $to, Heroicon $icon, string $color): Action
    {
        return Action::make($name)
            ->label(__("submission_decisions.actions.{$name}"))
            ->icon($icon)
            ->color($color)
            ->modalSubmitActionLabel(__("submission_decisions.actions.{$name}"))
            ->schema([
                Textarea::make('author_comment')
                    ->label(__('submission_decisions.author_comment.label'))
                    ->helperText(__('submission_decisions.author_comment.help'))
                    ->maxLength(SubmissionStatusChange::COMMENT_MAX)
                    ->rows(4),
            ])
            ->visible(fn (Submission $record): bool => self::isOfCall($record)
                && SubmissionTransitions::isAllowed($record->status, $to)
                && ! SubmissionTransitions::isReversal($record->status, $to)
                && Gate::allows('decide', $record));
    }

    /** Los invitados (plan 021) no son propuestas de una convocatoria: no se deciden desde aquí. */
    private static function isOfCall(Submission $record): bool
    {
        return $record->origin === SubmissionOrigin::Call;
    }

    /** @param  array<string, mixed>  $data */
    private static function comment(array $data): ?string
    {
        return self::text($data, 'author_comment');
    }

    /** @param  array<string, mixed>  $data */
    private static function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Ejecuta la operación y muestra su resultado. Un conflicto, una transición inválida o una validación
     * no rompen la pantalla: se avisan y se recarga el estado vigente de la propuesta.
     *
     * @param  callable(): mixed  $operation
     * @param  (Closure(): string)|null  $afterNotice  texto adicional del aviso de éxito
     */
    private static function run(Submission $record, callable $operation, string $successTitle, ?Closure $afterNotice = null): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            self::fail(ActionErrors::summary($exception));

            return;
        } catch (SubmissionStatusConflict|InvalidSubmissionTransition|PublishedDecisionNeedsConfirmation $exception) {
            self::fail($exception->getMessage());
            $record->refresh();

            return;
        }

        $record->refresh();

        $notification = Notification::make()->title($successTitle)->success();
        $extra = $afterNotice === null ? '' : $afterNotice();

        if ($extra !== '') {
            $notification->body($extra);
        }

        $notification->send();
    }

    private static function fail(string $message): void
    {
        Notification::make()
            ->title(__('submission_decisions.actions.failed'))
            ->body($message)
            ->danger()
            ->send();
    }
}
