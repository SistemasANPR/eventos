<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Reviews\ComputeSubmissionResults;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Filament\Support\ActionErrors;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Review;
use App\Models\Track;
use App\Support\Reviews\SubmissionResult;
use BackedEnum;
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
 * Resultados de la evaluación del evento (plan 011, F6, H-011-5, CA-011-22 a 24): ranking por propuesta con
 * promedio ponderado, desviación y evaluaciones incompletas, y la vista de discrepancias con las calificaciones
 * de cada evaluador. Delgada: el cálculo es de `ComputeSubmissionResults`, que además autoriza. Entra quien tiene
 * `reviews.view_results` (403 si no); un evento de otra organización responde 404. Pagina con máximo 100.
 */
class ViewEventReviewResults extends ViewRecord
{
    use WithPagination;

    /** Tamaños de página ofrecidos; el tope es 100. */
    private const PAGE_SIZES = [25, 50, 100];

    protected static string $resource = EventResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    #[Url]
    public ?string $track = null;

    #[Url]
    public ?string $call = null;

    #[Url]
    public bool $onlyDiscrepancies = false;

    public int $perPage = 25;

    public static function getNavigationLabel(): string
    {
        return __('review_results.results.navigation');
    }

    public function getTitle(): string|Htmlable
    {
        return __('review_results.results.title', ['name' => EventsTable::displayName($this->event())]);
    }

    /** Sin el permiso de ver resultados no se llega ni a ver la pantalla. */
    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('viewResults', [Review::class, $this->event()]), 403);
    }

    public function updatedTrack(): void
    {
        $this->resetPage();
    }

    public function updatedCall(): void
    {
        $this->resetPage();
    }

    public function updatedOnlyDiscrepancies(): void
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
            View::make('filament.reviews.results')->viewData(fn (): array => $this->viewData()),
        ]);
    }

    /** @return array<string, mixed> */
    private function viewData(): array
    {
        $event = $this->event();
        $results = $this->results($event);

        if ($this->onlyDiscrepancies) {
            $results = array_values(array_filter($results, static fn (SubmissionResult $result): bool => $result->hasDiscrepancies()));
        }

        $perPage = in_array($this->perPage, self::PAGE_SIZES, true) ? $this->perPage : self::PAGE_SIZES[0];
        $page = Paginator::resolveCurrentPage();
        $locale = app()->getLocale();

        return [
            'results' => new LengthAwarePaginator(
                array_slice($results, ($page - 1) * $perPage, $perPage),
                count($results),
                $perPage,
                $page,
                ['path' => Paginator::resolveCurrentPath()],
            ),
            'onlyDiscrepancies' => $this->onlyDiscrepancies,
            'pageSizes' => self::PAGE_SIZES,
            'tracks' => Track::query()->where('event_id', $event->id)->orderBy('position')->get()
                ->mapWithKeys(static fn (Track $track): array => [$track->id => $track->label($locale)])->all(),
            'calls' => CallForSubmissions::query()->where('event_id', $event->id)->get()
                ->mapWithKeys(static fn (CallForSubmissions $call): array => [$call->id => (string) $call->getTranslation('title', $locale, true)])->all(),
        ];
    }

    /** @return list<SubmissionResult> */
    private function results(Event $event): array
    {
        $compute = app(ComputeSubmissionResults::class);

        try {
            return $compute->handle(ActionErrors::actor(), $event, ['track_id' => $this->track, 'call_id' => $this->call]);
        } catch (ValidationException) {
            // Un filtro que no es de este evento se quita y se avisa; no se muestra una lista engañosa.
            $this->track = null;
            $this->call = null;
            Notification::make()->title(__('review_results.results.filter_reset_notice'))->warning()->send();

            return $compute->handle(ActionErrors::actor(), $event);
        }
    }

    private function event(): Event
    {
        $record = $this->getRecord();

        return $record instanceof Event ? $record : throw new LogicException('Los resultados necesitan un evento.');
    }
}
