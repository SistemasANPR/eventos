<?php

declare(strict_types=1);

namespace App\Livewire\Program;

use App\Models\Event;
use App\Support\Program\Agenda\BuildsAgenda;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Agenda de lectura de un evento (plan 031, F4): la cuadrícula día × lugar del panel. ESQUELETO del contrato: el
 * carril de la interfaz lo reemplaza entero (autorización, cuadrícula, selector de día, panel de pendientes y estados).
 * Se monta con `@livewire(EventAgenda::class, ['eventId' => $event->id])` desde `ManageEventActivities`.
 */
class EventAgenda extends Component
{
    #[Locked]
    public string $eventId = '';

    #[Url(as: 'dia', except: '')]
    public ?string $date = null;

    public function mount(string $eventId): void
    {
        $this->eventId = $eventId;
    }

    public function render(): View
    {
        $event = Event::query()->findOrFail($this->eventId);

        return view('livewire.program.event-agenda', ['agenda' => app(BuildsAgenda::class)->day($event, $this->date)]);
    }
}
