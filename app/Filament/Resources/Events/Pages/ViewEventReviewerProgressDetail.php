<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Reviews\ShowReviewerProgress;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\ActionErrors;
use App\Models\Event;
use App\Models\Review;
use App\Support\Reviews\ReviewerProgressDetailReport;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Livewire\WithPagination;
use LogicException;

/**
 * Detalle del avance de un evaluador (plan 011, F6, H-011-6, CA-011-58 y 59): sus propuestas con el estado de cada
 * asignación y la fecha de su última actividad. Sin calificaciones ni comentarios. Entra quien tiene
 * `reviews.view_progress` (403 si no, antes de buscar al evaluador); un evaluador que no es de ESTE evento (de otro
 * evento, de otra organización o inexistente) responde 404. Pagina con máximo 100.
 */
class ViewEventReviewerProgressDetail extends ViewRecord
{
    use WithPagination;

    protected static string $resource = EventResource::class;

    public string $reviewer = '';

    public int $perPage = 25;

    /** Sin entrada propia en el menú: se llega desde la lista de avance, que mantiene activa su sección. */
    public static function getNavigationLabel(): string
    {
        return __('review_results.progress.navigation');
    }

    public function mount(int|string $record, string $reviewer = ''): void
    {
        $this->reviewer = $reviewer;

        // 403 sin permiso (authorizeAccess) y, después, 404 si el evaluador no es de este evento.
        parent::mount($record);
        app(ShowReviewerProgress::class)->handle(ActionErrors::actor(), $this->event(), $this->reviewer, 1);
    }

    public function getTitle(): string|Htmlable
    {
        return __('review_results.detail.title', ['reviewer' => $this->report()->name]);
    }

    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('viewProgress', [Review::class, $this->event()]), 403);
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.reviews.progress-detail')->viewData(fn (): array => [
                'report' => $this->report(),
                'pageSizes' => [25, 50, 100],
                'timezone' => $this->event()->timezone,
            ]),
        ]);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('review_results.detail.back'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => EventResource::getUrl('review-progress', ['record' => $this->event()])),
        ];
    }

    private function report(): ReviewerProgressDetailReport
    {
        $perPage = in_array($this->perPage, [25, 50, 100], true) ? $this->perPage : 25;

        return app(ShowReviewerProgress::class)->handle(ActionErrors::actor(), $this->event(), $this->reviewer, $perPage, $this->getPage());
    }

    private function event(): Event
    {
        $record = $this->getRecord();

        return $record instanceof Event ? $record : throw new LogicException('El detalle necesita un evento.');
    }
}
