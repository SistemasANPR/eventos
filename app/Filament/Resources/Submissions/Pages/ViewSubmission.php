<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Pages;

use App\Filament\Resources\Submissions\SubmissionResource;
use App\Filament\Resources\Submissions\Support\SubmissionDetail;
use App\Models\Event;
use App\Models\Submission;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use LogicException;

/**
 * Detalle de una propuesta (plan 009, H-009-2): respuestas con la etiqueta de su instantánea,
 * participantes y archivos. La Policy `view` autoriza (`submissions.view`); la propuesta se busca
 * dentro del evento de la ruta y de la organización del tenant, así que una ajena responde 404
 * (CA-009-7). Cada apertura queda en la auditoría, sin datos personales (docs/privacidad.md).
 */
class ViewSubmission extends ViewRecord
{
    protected static string $resource = SubmissionResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $submission = $this->submission();
        $user = auth()->user();

        activity()
            ->causedBy($user)
            ->performedOn($submission)
            ->event('submission_viewed')
            ->withProperties(['event_id' => $submission->event_id])
            ->log('submission_viewed');
    }

    /**
     * El detalle es de solo lectura y se arma con `SubmissionDetail`: no se vuelca el registro entero
     * (respuestas e instantánea del formulario) al estado de Livewire que viaja al navegador.
     */
    protected function fillForm(): void {}

    public function getTitle(): string
    {
        return $this->submission()->title;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.submissions.detail')->viewData(fn (): array => $this->viewData()),
        ]);
    }

    /** @return array<string, mixed> */
    private function viewData(): array
    {
        $submission = $this->submission();
        $user = auth()->user();
        assert($user instanceof User);
        $event = $this->getParentRecord();
        $timezone = $event instanceof Event ? $event->timezone : (string) config('app.timezone');

        return [
            'submission' => $submission,
            'detail' => SubmissionDetail::for($submission, $user, CurrentOrganization::get()),
            'submittedAt' => $submission->submitted_at->setTimezone($timezone)->isoFormat('LLL').' ('.$timezone.')',
        ];
    }

    private function submission(): Submission
    {
        $record = $this->getRecord();

        return $record instanceof Submission ? $record : throw new LogicException('El detalle no tiene una propuesta.');
    }
}
