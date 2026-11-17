<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Pages;

use App\Actions\SubmissionExports\ExportSubmissions;
use App\Exceptions\Domain\SubmissionExportTooLarge;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Filament\Resources\Submissions\Tables\SubmissionsTable;
use App\Models\Event;
use App\Models\Submission;
use App\Models\User;
use App\Support\SubmissionExport\RecentExports;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Lista de propuestas del evento de la ruta (plan 009, H-009-1) y su exportación (H-009-3). */
class ListSubmissions extends ListRecords
{
    protected static string $resource = SubmissionResource::class;

    /**
     * Un filtro con un valor que no es del evento actual se limpia y produce el mismo error de
     * validación genérico, sea ajeno, inexistente o mal formado (CA-009-4).
     */
    public function updatedTableFilters(): void
    {
        $event = $this->getParentRecord();

        if ($event instanceof Event) {
            $invalid = SubmissionsTable::invalidSelectFilters($this->tableFilters ?? [], $event);

            foreach ($invalid as $name) {
                $this->tableFilters[$name]['value'] = null;
            }

            if ($invalid !== []) {
                throw SubmissionsTable::invalidFilter();
            }
        }

        parent::updatedTableFilters();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('submission_panel.export.action'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => Gate::allows('export', Submission::class))
                ->modalDescription(__('submission_panel.export.description'))
                ->schema([
                    Select::make('format')
                        ->label(__('submission_panel.export.format'))
                        ->options(['xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV (.csv)'])
                        ->default('xlsx')
                        ->required()
                        ->native(false),
                ])
                ->action(fn (array $data) => $this->requestExport((string) $data['format'])),
            Action::make('recentExports')
                ->label(__('submission_panel.export.recent'))
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->visible(fn (): bool => Gate::allows('export', Submission::class))
                ->modalHeading(__('submission_panel.export.recent'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('filament-actions::modal.actions.close.label'))
                ->modalContent(fn () => view('filament.submissions.recent-exports', [
                    'exports' => RecentExports::for($this->user(), CurrentOrganization::get()),
                ])),
        ];
    }

    /**
     * Pide la exportación con los filtros aplicados. El servidor los vuelve a validar y recalcula la
     * consulta: lo que seleccionó la persona no se toma como cierto. Un fallo se muestra, no se oculta.
     */
    private function requestExport(string $format): void
    {
        $event = $this->getParentRecord();

        if (! $event instanceof Event) {
            throw new LogicException('La lista no tiene un evento.');
        }

        try {
            app(ExportSubmissions::class)->handle($event, $this->exportFilters(), $this->user(), $format);
        } catch (SubmissionExportTooLarge $exception) {
            Notification::make()->danger()->title(__('submission_panel.export.action'))->body($exception->getMessage())->persistent()->send();

            return;
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title(__('submission_panel.export.action'))->body(implode(' ', array_merge(...array_values($exception->errors()))))->persistent()->send();

            return;
        }

        Notification::make()->success()->title(__('submission_panel.export.queued_title'))->body(__('submission_panel.export.queued_body'))->send();
    }

    /**
     * Filtros activos de la tabla, con la forma que espera la exportación.
     *
     * @return array<string, mixed>
     */
    private function exportFilters(): array
    {
        $filters = $this->tableFilters ?? [];

        return [
            'call_for_submissions_id' => $filters['call_for_submissions_id']['value'] ?? null,
            'track_id' => $filters['track_id']['value'] ?? null,
            'submission_type_id' => $filters['submission_type_id']['value'] ?? null,
            'status' => $filters['status']['value'] ?? null,
            'from' => $filters['submitted_range']['from'] ?? null,
            'until' => $filters['submitted_range']['until'] ?? null,
        ];
    }

    private function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : throw new LogicException('No hay una sesión de organizador.');
    }
}
