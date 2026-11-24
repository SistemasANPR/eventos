<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Actions;

use App\Actions\Submissions\BulkDecideSubmissions;
use App\Actions\Submissions\PublishResults;
use App\Actions\Submissions\RevertDecisionBatch;
use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\PublishedDecisionNeedsConfirmation;
use App\Exceptions\Domain\SubmissionStatusConflict;
use App\Filament\Support\ActionErrors;
use App\Http\Requests\Submissions\BulkDecideRequest;
use App\Http\Requests\Submissions\PublishResultsRequest;
use App\Models\CallForSubmissions;
use App\Models\DecisionBatch;
use App\Models\Event;
use App\Models\Submission;
use App\Models\SubmissionStatusChange;
use App\Support\Events\EventTime;
use App\Support\Submissions\BatchReversalReport;
use App\Support\Submissions\BulkDecisionSelection;
use App\Support\Submissions\PendingResults;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Decidir en masa, revertir un lote y publicar los resultados desde la lista de propuestas del evento (plan
 * 012, F2, H-012-2 y H-012-3). Delgadas: la regla de negocio y la autorización viven en `BulkDecideSubmissions`,
 * `RevertDecisionBatch` y `PublishResults`; aquí solo se oculta lo que no procede (ocultar no es autorizar), se
 * muestra antes de confirmar cuántas propuestas se afectarán por estado y se informa el resultado sin omitir
 * nada en silencio: las no elegibles se listan como omitidas y un fallo se muestra, no se traga.
 */
final class BulkDecisionActions
{
    /** Máximo de propuestas que un aviso lista una por una; el resto se resume en una cifra. */
    private const MAX_LISTED = 10;

    /** «Aceptar / Lista de espera / Rechazar seleccionadas» sobre las propuestas marcadas en la lista. */
    public static function bulk(string $name, SubmissionStatus $to, Heroicon $icon, string $color): BulkAction
    {
        return BulkAction::make($name)
            ->label(__("submission_decisions.bulk.{$name}"))
            ->icon($icon)
            ->color($color)
            ->modalHeading(__("submission_decisions.bulk.{$name}"))
            ->modalDescription(fn (Collection $records): string => self::preview(BulkDecisionSelection::for($records->all(), $to)))
            ->modalSubmitActionLabel(__("submission_decisions.bulk.{$name}"))
            ->schema([self::commentField()])
            ->visible(fn (): bool => Gate::allows('decideMany', Submission::class))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data, $livewire) use ($to): void {
                self::apply(self::event($livewire), BulkDecisionSelection::for($records->all(), $to), $data, self::filters($livewire, 'selection'));
            });
    }

    /** «Decidir sobre el filtro»: la selección es todo lo que la lista muestra con los filtros aplicados (hasta el máximo). */
    public static function decideFiltered(): Action
    {
        return Action::make('decideFiltered')
            ->label(__('submission_decisions.bulk.filtered'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('gray')
            ->modalHeading(__('submission_decisions.bulk.filtered'))
            ->modalDescription(__('submission_decisions.bulk.filtered_description', ['max' => BulkDecideRequest::MAX_SUBMISSIONS]))
            ->modalSubmitActionLabel(__('submission_decisions.bulk.apply'))
            ->schema(fn ($livewire): array => [
                Select::make('to')
                    ->label(__('submission_decisions.bulk.to'))
                    ->options(self::targetOptions())
                    ->required()
                    ->live()
                    ->native(false),
                Text::make(fn (Get $get): string => self::filteredPreview($livewire, $get('to'))),
                self::commentField(),
            ])
            ->visible(fn (): bool => Gate::allows('decideMany', Submission::class))
            ->action(function (array $data, $livewire): void {
                $to = SubmissionStatus::tryFrom((string) ($data['to'] ?? ''));

                if ($to === null || ! in_array($to, BulkDecideRequest::DECISIONS, true)) {
                    self::fail(__('submission_decisions.bulk.errors.invalid_target'));

                    return;
                }

                self::apply(self::event($livewire), BulkDecisionSelection::for(self::filteredRecords($livewire)->all(), $to), $data, self::filters($livewire, 'filter'));
            });
    }

    /** «Revertir lote»: devuelve a revisión las propuestas de una decisión masiva (CA-012-12 y 13). */
    public static function revertBatch(): Action
    {
        return Action::make('revertBatch')
            ->label(__('submission_decisions.bulk.revert_batch'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->modalHeading(__('submission_decisions.bulk.revert_batch'))
            ->modalDescription(__('submission_decisions.bulk.revert_batch_description'))
            ->modalSubmitActionLabel(__('submission_decisions.bulk.revert_batch'))
            ->schema(fn ($livewire): array => [
                Select::make('batch_id')
                    ->label(__('submission_decisions.bulk.batch'))
                    ->options(self::batchOptions(self::event($livewire)))
                    ->required()
                    ->native(false),
                Textarea::make('reason')
                    ->label(__('submission_decisions.actions.reason'))
                    ->maxLength(SubmissionStatusChange::COMMENT_MAX)
                    ->rows(3),
                Checkbox::make('confirm_published')
                    ->label(__('submission_decisions.actions.confirm_published')),
            ])
            ->visible(fn (): bool => Gate::allows('revertMany', Submission::class))
            ->action(function (array $data, $livewire): void {
                $event = self::event($livewire);
                $batch = DecisionBatch::query()->where('event_id', $event->id)->findOrFail((string) ($data['batch_id'] ?? ''));

                try {
                    $report = app(RevertDecisionBatch::class)->handle(
                        ActionErrors::actor(),
                        $batch,
                        is_string($data['reason'] ?? null) ? $data['reason'] : null,
                        (bool) ($data['confirm_published'] ?? false),
                    );
                } catch (ValidationException $exception) {
                    self::fail(ActionErrors::summary($exception));

                    return;
                } catch (PublishedDecisionNeedsConfirmation|SubmissionStatusConflict $exception) {
                    self::fail($exception->getMessage());

                    return;
                }

                self::reportReversal($report);
            });
    }

    /** «Publicar resultados» de una convocatoria: fija la publicación y avisa a los autores (DA-2). */
    public static function publishResults(): Action
    {
        return Action::make('publishResults')
            ->label(__('submission_decisions.publish.action'))
            ->icon(Heroicon::OutlinedMegaphone)
            ->modalHeading(__('submission_decisions.publish.action'))
            ->modalDescription(__('submission_decisions.publish.description'))
            ->modalSubmitActionLabel(__('submission_decisions.publish.submit'))
            ->schema(fn ($livewire): array => [
                Select::make('call_id')
                    ->label(__('submission_decisions.publish.call'))
                    ->options(self::callOptions(self::event($livewire)))
                    ->required()
                    ->live()
                    ->native(false),
                Text::make(fn (Get $get): string => self::publishPreview(self::event($livewire), $get('call_id'))),
            ])
            ->visible(fn (): bool => Gate::allows('decideMany', Submission::class))
            ->action(function (array $data, $livewire): void {
                try {
                    $input = Validator::make($data, (new PublishResultsRequest)->rules())->validate();
                } catch (ValidationException $exception) {
                    self::fail(ActionErrors::summary($exception));

                    return;
                }

                // Una convocatoria de otro evento u otra organización no se resuelve: 404 (CA-012-22).
                $call = CallForSubmissions::query()->where('event_id', self::event($livewire)->id)->findOrFail((string) $input['call_id']);
                $report = app(PublishResults::class)->handle(ActionErrors::actor(), $call);

                $lines = [__('submission_decisions.publish.done', ['count' => $report->published])];

                if ($report->alreadyPublished) {
                    $lines[] = __('submission_decisions.publish.already');
                }

                if ($report->failed > 0) {
                    $lines[] = __('submission_decisions.publish.failed', ['count' => $report->failed]);
                }

                $notification = Notification::make()
                    ->title(__('submission_decisions.publish.title'))
                    ->body(self::body($lines))
                    ->persistent();

                if ($report->failed > 0) {
                    $notification->warning();
                } else {
                    $notification->success();
                }

                $notification->send();
            });
    }

    /**
     * Aplica la decisión sobre las elegibles y muestra el informe, con las omitidas listadas (CA-012-10: la
     * Action rechaza la operación entera si alguna no fuera elegible; aquí solo viajan las elegibles).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $filter
     */
    private static function apply(Event $event, BulkDecisionSelection $selection, array $data, array $filter): void
    {
        if ($selection->eligible === []) {
            self::fail(__('submission_decisions.bulk.errors.none_eligible', ['to' => $selection->to->getLabel()]).self::omittedLines($selection));

            return;
        }

        try {
            $report = app(BulkDecideSubmissions::class)->handle(ActionErrors::actor(), $event, [
                'to' => $selection->to->value,
                'submission_ids' => $selection->eligibleIds(),
                'author_comment' => is_string($data['author_comment'] ?? null) ? $data['author_comment'] : null,
                'filter' => $filter,
            ]);
        } catch (ValidationException $exception) {
            self::fail(ActionErrors::summary($exception));

            return;
        }

        $lines = [__('submission_decisions.bulk.done', ['count' => $report->total, 'to' => $report->to->getLabel()])];

        if ($report->publishedNow > 0) {
            $lines[] = __('submission_decisions.bulk.published_now', ['count' => $report->publishedNow]);
        }

        $omitted = self::omittedLines($selection);

        if ($omitted !== '') {
            $lines[] = trim($omitted);
        }

        Notification::make()
            ->title(__('submission_decisions.bulk.done_title'))
            ->body(self::body($lines))
            ->success()
            ->persistent()
            ->send();
    }

    private static function reportReversal(BatchReversalReport $report): void
    {
        $lines = [__('submission_decisions.bulk.reverted', ['count' => $report->reverted])];

        foreach ($report->programNotices as $notice) {
            $lines[] = $notice;
        }

        if ($report->skippedCount() > 0) {
            $lines[] = __('submission_decisions.bulk.reverted_skipped', ['count' => $report->skippedCount()]);

            foreach (array_slice($report->skipped, 0, self::MAX_LISTED) as $item) {
                $lines[] = '• '.$item['reference'].' «'.$item['title'].'» ('.__("submission_decisions.bulk.skip_reasons.{$item['reason']}").')';
            }

            if ($report->skippedCount() > self::MAX_LISTED) {
                $lines[] = __('submission_decisions.bulk.more', ['count' => $report->skippedCount() - self::MAX_LISTED]);
            }
        }

        $notification = Notification::make()
            ->title(__('submission_decisions.bulk.revert_title'))
            ->body(self::body($lines))
            ->persistent();

        if ($report->reverted === 0) {
            $notification->warning();
        } else {
            $notification->success();
        }

        $notification->send();
    }

    /** Texto de la confirmación: cuántas se afectarán por estado y cuántas se omitirán. */
    private static function preview(BulkDecisionSelection $selection): string
    {
        if ($selection->eligible === []) {
            return __('submission_decisions.bulk.errors.none_eligible', ['to' => $selection->to->getLabel()]).self::omittedLines($selection);
        }

        $byStatus = collect($selection->eligibleByStatus())->map(fn (int $count, string $label): string => "{$count} {$label}")->implode(', ');
        $text = __('submission_decisions.bulk.preview', [
            'count' => count($selection->eligible),
            'to' => $selection->to->getLabel(),
            'by_status' => $byStatus,
        ]);

        if ($selection->omitted !== []) {
            $text .= ' '.__('submission_decisions.bulk.preview_omitted', ['count' => count($selection->omitted)]);
        }

        return $text.' '.__('submission_decisions.bulk.preview_notice');
    }

    private static function filteredPreview(mixed $livewire, mixed $to): string
    {
        $target = SubmissionStatus::tryFrom((string) $to);

        if ($target === null) {
            return __('submission_decisions.bulk.pick_target');
        }

        return self::preview(BulkDecisionSelection::for(self::filteredRecords($livewire)->all(), $target));
    }

    private static function publishPreview(Event $event, mixed $callId): string
    {
        $call = $callId === null || $callId === '' ? null : CallForSubmissions::query()->where('event_id', $event->id)->find((string) $callId);

        if (! $call instanceof CallForSubmissions) {
            return __('submission_decisions.publish.pick_call');
        }

        $counts = PendingResults::countsByStatus($call);
        $byStatus = collect($counts)
            ->map(fn (int $count, string $status): string => $count.' '.SubmissionStatus::from($status)->getLabel())
            ->implode(', ');

        $text = $counts === []
            ? __('submission_decisions.publish.nothing_pending')
            : __('submission_decisions.publish.preview', ['count' => array_sum($counts), 'by_status' => $byStatus]);

        if ($call->results_at !== null) {
            $text .= ' '.__('submission_decisions.publish.results_at', ['date' => EventTime::for($event)->dateTime($call->results_at, __('submission_panel.date_format'), ' ')]);
        }

        return $call->results_published_at === null ? $text : $text.' '.__('submission_decisions.publish.already');
    }

    /**
     * Las propuestas que la lista muestra con los filtros aplicados, hasta un registro más que el máximo para
     * que la Action rechace por exceso y no decida sobre una selección recortada en silencio (CA-012-11).
     *
     * @return Collection<int, Submission>
     */
    private static function filteredRecords(mixed $livewire): Collection
    {
        $query = is_object($livewire) && method_exists($livewire, 'getFilteredTableQuery') ? $livewire->getFilteredTableQuery() : null;

        if ($query === null) {
            throw new LogicException('Decidir sobre el filtro necesita la lista de propuestas.');
        }

        return $query->reorder()->orderBy('submissions.id')->limit(BulkDecideRequest::MAX_SUBMISSIONS + 1)->get();
    }

    /**
     * El filtro de la lista, solo para la auditoría del lote.
     *
     * @return array<string, mixed>
     */
    private static function filters(mixed $livewire, string $mode): array
    {
        $active = is_object($livewire) && method_exists($livewire, 'activeFilters') ? $livewire->activeFilters() : [];

        return ['mode' => $mode, ...$active];
    }

    /** @return array<string, string> */
    private static function targetOptions(): array
    {
        $options = [];

        foreach (BulkDecideRequest::DECISIONS as $status) {
            $options[$status->value] = $status->getLabel();
        }

        return $options;
    }

    /** @return array<string, string> los últimos lotes del evento, el más reciente primero */
    private static function batchOptions(Event $event): array
    {
        $time = EventTime::for($event);

        return DecisionBatch::query()
            ->where('event_id', $event->id)
            ->with('actor')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get()
            ->mapWithKeys(fn (DecisionBatch $batch): array => [
                $batch->id => $time->dateTime($batch->created_at, __('submission_panel.date_format'), ' ').' — '
                    .$batch->actor->name.' — '
                    .__('submission_decisions.bulk.batch_summary', [
                        'count' => $batch->total,
                        'to' => SubmissionStatus::tryFrom((string) ($batch->filter['to'] ?? ''))?->getLabel() ?? '',
                    ]),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private static function callOptions(Event $event): array
    {
        return CallForSubmissions::query()->where('event_id', $event->id)->get()
            ->mapWithKeys(fn (CallForSubmissions $call): array => [$call->id => (string) $call->getTranslation('title', app()->getLocale(), true)])
            ->all();
    }

    /** Las propuestas omitidas con su motivo, una por línea (hasta el máximo listado). */
    private static function omittedLines(BulkDecisionSelection $selection): string
    {
        if ($selection->omitted === []) {
            return '';
        }

        $byStatus = collect($selection->omittedByStatus())->map(fn (int $count, string $label): string => "{$count} {$label}")->implode(', ');

        return ' '.__('submission_decisions.bulk.omitted', [
            'count' => count($selection->omitted),
            'to' => $selection->to->getLabel(),
            'by_status' => $byStatus,
        ]);
    }

    private static function commentField(): Textarea
    {
        return Textarea::make('author_comment')
            ->label(__('submission_decisions.author_comment.label'))
            ->helperText(__('submission_decisions.bulk.comment_help'))
            ->maxLength(SubmissionStatusChange::COMMENT_MAX)
            ->rows(4);
    }

    /**
     * El cuerpo de un aviso admite HTML: cada línea se escapa porque lleva títulos que escribieron otras personas.
     *
     * @param  list<string>  $lines
     */
    private static function body(array $lines): string
    {
        return implode('<br>', array_map(static fn (string $line): string => e($line), $lines));
    }

    private static function fail(string $message): void
    {
        Notification::make()
            ->title(__('submission_decisions.actions.failed'))
            ->body($message)
            ->danger()
            ->persistent()
            ->send();
    }

    private static function event(mixed $livewire): Event
    {
        $event = is_object($livewire) && method_exists($livewire, 'getOwnerRecord') ? $livewire->getOwnerRecord() : null;

        return $event instanceof Event ? $event : throw new LogicException('Decidir en masa necesita un evento (el dueño de la página).');
    }
}
