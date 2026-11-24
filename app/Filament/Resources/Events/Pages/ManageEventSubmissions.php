<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\SubmissionExports\ExportSubmissions;
use App\Exceptions\Domain\SubmissionExportTooLarge;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Submissions\Actions\ReviewAssignmentActions;
use App\Filament\Resources\Submissions\SubmissionResource;
use App\Filament\Resources\Submissions\Tables\SubmissionsTable;
use App\Models\Event;
use App\Models\Submission;
use App\Models\User;
use App\Support\SubmissionExport\RecentExports;
use App\Support\SubmissionSummary\SubmissionSummary;
use App\Support\Tenancy\CurrentOrganization;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Propuestas del evento (plan 020, F2; recurso del plan 009): la lista que antes se abría solo por URL
 * pasa a ser una sección del evento. La tabla viene de `SubmissionResource`, anidado bajo el evento;
 * el evento sale de la ruta, así que un evento de otra organización responde 404. Quién la ve lo
 * decide `SubmissionPolicy`. Solo lectura, salvo la exportación (plan 009, H-009-3): las acciones
 * «Exportar» y «Exportaciones recientes» aparecen solo con `submissions.export`.
 */
class ManageEventSubmissions extends ManageRelatedRecords
{
    protected static string $resource = EventResource::class;

    protected static string $relationship = 'submissions';

    protected static ?string $relatedResource = SubmissionResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    public static function getNavigationLabel(): string
    {
        return __('submission_panel.navigation');
    }

    /** La sección se llama como su entrada de menú, no «Administrar Propuestas Evento». */
    public function getTitle(): string
    {
        return __('submission_panel.navigation');
    }

    /**
     * La página de relación arma su consulta desde `$evento->submissions()` y no pasa por
     * `SubmissionResource::getEloquentQuery()`: sin esto volverían los invitados (plan 018) y se
     * perdería la carga anticipada. Se aplican las reglas de la lista a la relación del evento
     * (Filament exige una relación aquí, no una consulta).
     *
     * @return HasMany<Submission, Event>
     */
    public function getRelationship(): HasMany
    {
        $event = $this->getOwnerRecord();

        if (! $event instanceof Event) {
            throw new LogicException('La página de propuestas no tiene un evento.');
        }

        return SubmissionResource::scopeToList($event->submissions());
    }

    /**
     * Un filtro con un valor que no es del evento actual se limpia y produce el mismo error de
     * validación genérico, sea ajeno, inexistente o mal formado (plan 009, CA-009-4).
     */
    public function updatedTableFilters(): void
    {
        $event = $this->getOwnerRecord();

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
            ReviewAssignmentActions::assignByScope(),
            ReviewAssignmentActions::invite(),
            Action::make('summary')
                ->label(__('submission_panel.summary.action'))
                ->icon(Heroicon::OutlinedChartBar)
                ->color('gray')
                ->visible(fn (): bool => Gate::allows('viewAny', Submission::class))
                ->modalHeading(__('submission_panel.summary.action'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('filament-actions::modal.actions.close.label'))
                ->modalContent(fn () => view('filament.submissions.summary', [
                    'summary' => SubmissionSummary::for($this->eventRecord()),
                ])),
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
     * Pide la exportación con los filtros aplicados (plan 009, H-009-3). El servidor los vuelve a validar
     * y recalcula la consulta: lo que seleccionó la persona no se toma como cierto. Un fallo se muestra.
     */
    private function requestExport(string $format): void
    {
        $event = $this->getOwnerRecord();

        if (! $event instanceof Event) {
            throw new LogicException('La página de propuestas no tiene un evento.');
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

    private function eventRecord(): Event
    {
        $event = $this->getOwnerRecord();

        return $event instanceof Event ? $event : throw new LogicException('La página de propuestas no tiene un evento.');
    }

    private function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : throw new LogicException('No hay una sesión de organizador.');
    }
}
