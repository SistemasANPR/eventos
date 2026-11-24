<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Reviews\CloseReviewPeriod;
use App\Actions\Reviews\ComputeReviewerProgress;
use App\Actions\Reviews\ReopenReviewPeriod;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Filament\Support\ActionErrors;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Review;
use App\Models\Track;
use App\Support\Events\EventTime;
use App\Support\Reviews\ReviewerProgressReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use LogicException;

/**
 * Avance de los evaluadores del evento y cierre del periodo (plan 011, F6, H-011-6, CA-011-25 y 55 a 59): por
 * evaluador el porcentaje y el conteo («80 % · 8 de 10») con su desglose, orden por porcentaje, filtros por
 * evaluador, eje y convocatoria, avance global y resaltado bajo un umbral. Nunca muestra calificaciones ni
 * comentarios. Delgada: el cálculo es de `ComputeReviewerProgress` (que autoriza) y el cierre y la reapertura,
 * de sus Actions. Entra quien tiene `reviews.view_progress` (403 si no); un evento de otra organización responde 404.
 */
class ViewEventReviewerProgress extends ViewRecord
{
    use WithPagination;

    /** Tamaños de página ofrecidos; el tope es 100. */
    private const PAGE_SIZES = [25, 50, 100];

    protected static string $resource = EventResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    #[Url]
    public ?string $reviewer = null;

    #[Url]
    public ?string $track = null;

    #[Url]
    public ?string $call = null;

    #[Url]
    public string $sort = 'asc';

    /** Porcentaje por debajo del cual un evaluador sale resaltado. */
    public int $threshold = 50;

    public int $perPage = 25;

    public static function getNavigationLabel(): string
    {
        return __('review_results.progress.navigation');
    }

    public function getTitle(): string|Htmlable
    {
        return __('review_results.progress.title', ['name' => EventsTable::displayName($this->event())]);
    }

    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('viewProgress', [Review::class, $this->event()]), 403);
    }

    public function updatedReviewer(): void
    {
        $this->resetPage();
    }

    public function updatedTrack(): void
    {
        $this->resetPage();
    }

    public function updatedCall(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.reviews.progress')->viewData(fn (): array => $this->viewData()),
        ]);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('closePeriod')
                ->label(__('review_results.period.close'))
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('danger')
                ->visible(fn (): bool => $this->event()->reviewPeriodIsOpen() && Gate::allows('closePeriod', [Review::class, $this->event()]))
                ->requiresConfirmation()
                ->modalHeading(__('review_results.period.close_heading'))
                ->modalDescription(__('review_results.period.close_description'))
                ->schema([Textarea::make('reason')->label(__('review_results.period.reason'))->maxLength(500)->rows(2)])
                ->action(function (array $data): void {
                    $this->changePeriod(fn (): Event => app(CloseReviewPeriod::class)->handle(ActionErrors::actor(), $this->event(), $data), __('review_results.period.closed'));
                }),
            Action::make('reopenPeriod')
                ->label(__('review_results.period.reopen'))
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('gray')
                ->visible(fn (): bool => ! $this->event()->reviewPeriodIsOpen() && Gate::allows('closePeriod', [Review::class, $this->event()]))
                ->requiresConfirmation()
                ->modalHeading(__('review_results.period.reopen_heading'))
                ->modalDescription(__('review_results.period.reopen_description'))
                ->schema([Textarea::make('reason')->label(__('review_results.period.reason'))->maxLength(500)->rows(2)])
                ->action(function (array $data): void {
                    $this->changePeriod(fn (): Event => app(ReopenReviewPeriod::class)->handle(ActionErrors::actor(), $this->event(), $data), __('review_results.period.reopened'));
                }),
        ];
    }

    /** @param  callable(): Event  $change */
    private function changePeriod(callable $change, string $successTitle): void
    {
        try {
            $change();
        } catch (ValidationException $exception) {
            Notification::make()->title(ActionErrors::summary($exception))->danger()->send();

            return;
        }

        // El registro del encabezado se vuelve a leer: el botón visible depende del estado nuevo.
        $this->getRecord()->refresh();
        Notification::make()->title($successTitle)->success()->send();
    }

    /** @return array<string, mixed> */
    private function viewData(): array
    {
        $event = $this->event();
        $report = $this->report($event);
        $perPage = in_array($this->perPage, self::PAGE_SIZES, true) ? $this->perPage : self::PAGE_SIZES[0];
        $page = Paginator::resolveCurrentPage();
        $locale = app()->getLocale();
        $threshold = max(0, min($this->threshold, 100));

        return [
            'rows' => new LengthAwarePaginator(
                array_slice($report->rows, ($page - 1) * $perPage, $perPage),
                count($report->rows),
                $perPage,
                $page,
                ['path' => Paginator::resolveCurrentPath()],
            ),
            'overall' => $report->overall,
            'threshold' => $threshold,
            'pageSizes' => self::PAGE_SIZES,
            'periodOpen' => $event->reviewPeriodIsOpen(),
            'closedAt' => $event->review_period_closed_at === null
                ? null
                : EventTime::for($event)->dateTime($event->review_period_closed_at, __('submission_panel.long_date_format'), ' '),
            'allReviewers' => $this->allReviewers($event),
            'tracks' => Track::query()->where('event_id', $event->id)->orderBy('position')->get()
                ->mapWithKeys(static fn (Track $track): array => [$track->id => $track->label($locale)])->all(),
            'calls' => CallForSubmissions::query()->where('event_id', $event->id)->get()
                ->mapWithKeys(static fn (CallForSubmissions $call): array => [$call->id => (string) $call->getTranslation('title', $locale, true)])->all(),
            'detailUrl' => static fn (string $reviewerId): string => EventResource::getUrl('review-progress-detail', ['record' => $event, 'reviewer' => $reviewerId]),
        ];
    }

    private function report(Event $event): ReviewerProgressReport
    {
        $compute = app(ComputeReviewerProgress::class);
        $direction = in_array($this->sort, ['asc', 'desc'], true) ? $this->sort : 'asc';

        try {
            return $compute->handle(ActionErrors::actor(), $event, ['reviewer_id' => $this->reviewer, 'track_id' => $this->track, 'call_id' => $this->call], $direction);
        } catch (ValidationException) {
            // Un filtro que no es de este evento se quita y se avisa; no se muestra una lista engañosa.
            $this->reviewer = null;
            $this->track = null;
            $this->call = null;
            Notification::make()->title(__('review_results.results.filter_reset_notice'))->warning()->send();

            return $compute->handle(ActionErrors::actor(), $event, [], $direction);
        }
    }

    /**
     * Opciones del filtro de evaluador: todos los del evento, sin importar el filtro aplicado.
     *
     * @return array<string, string>
     */
    private function allReviewers(Event $event): array
    {
        $all = app(ComputeReviewerProgress::class)->handle(ActionErrors::actor(), $event);

        $options = [];

        foreach ($all->rows as $row) {
            $options[$row->reviewerId] = $row->name;
        }

        return $options;
    }

    private function event(): Event
    {
        $record = $this->getRecord();

        return $record instanceof Event ? $record : throw new LogicException('El avance necesita un evento.');
    }
}
