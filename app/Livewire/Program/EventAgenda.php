<?php

declare(strict_types=1);

namespace App\Livewire\Program;

use App\Models\Event;
use App\Models\EventActivity;
use App\Support\Events\EventTime;
use App\Support\Program\Agenda\AgendaColumn;
use App\Support\Program\Agenda\AgendaColumnKind;
use App\Support\Program\Agenda\AgendaDay;
use App\Support\Program\Agenda\BuildsAgenda;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Agenda de lectura de un evento (plan 031, F4): la cuadrícula día × lugar del panel, solo lectura. Se monta con
 * `@livewire(EventAgenda::class, ['eventId' => $event->id])` desde `ManageEventActivities`.
 *
 * - **Autorización en el servidor (CA-031-24):** `boot()` corre en CADA petición del componente (al montar y en cada
 *   acción posterior), así que quien no tenga `activities.manage` en la organización recibe 403 aunque llame al
 *   componente directo. El evento se consulta por el modelo, que filtra por organización: uno ajeno responde 404.
 * - **El servidor decide:** el navegador solo pide un día; `BuildsAgenda` arma lo que se dibuja. Un día mal formado se
 *   ignora y un fallo de lectura se muestra como error y se reporta, nunca como una agenda vacía (CA-031-25).
 */
class EventAgenda extends Component
{
    #[Locked]
    public string $eventId = '';

    #[Url(as: 'dia', except: '')]
    public ?string $date = null;

    private ?Event $event = null;

    public function boot(): void
    {
        Gate::authorize('viewAny', EventActivity::class);
    }

    public function mount(string $eventId): void
    {
        $this->eventId = $eventId;
        $this->event();
    }

    /** Cambia el día mostrado sin recargar la página; un valor que no sea una fecha se ignora al dibujar. */
    public function selectDay(string $day): void
    {
        $this->date = $day;
    }

    public function render(): View
    {
        $event = $this->event();

        try {
            $agenda = app(BuildsAgenda::class)->day($event, $this->requestedDay());
        } catch (Throwable $exception) {
            report($exception);

            return view('livewire.program.event-agenda', ['event' => $event, 'agenda' => null, 'failed' => true]);
        }

        return view('livewire.program.event-agenda', ['event' => $event, 'agenda' => $agenda, 'failed' => false]);
    }

    /** Hora local (`H:i` o `H:i:s` del evento) de un minuto del día, con el formato de 12 h o 24 h del evento. */
    public function timeLabel(int $minute): string
    {
        $minute = max(0, min($minute, 24 * 60));
        $time = sprintf('%02d:%02d', intdiv($minute, 60) % 24, $minute % 60);

        return EventTime::for($this->event())->localTime($time);
    }

    /** Texto corto del botón de un día: «mié 10 mar». */
    public function dayLabel(string $day): string
    {
        return $this->parse($day)->locale(app()->getLocale())->translatedFormat('D j M');
    }

    /** Texto completo de un día para lectores de pantalla: «miércoles, 10 de marzo de 2027». */
    public function dayName(string $day): string
    {
        return $this->parse($day)->locale(app()->getLocale())->isoFormat('dddd, LL');
    }

    /** Nombre de la cabecera de una columna: el de la sala o el texto fijo de «Fuera de sede» y «Sin lugar». */
    public function columnLabel(AgendaColumn $column): string
    {
        return match ($column->kind) {
            AgendaColumnKind::Room => (string) $column->label,
            AgendaColumnKind::External => __('program.agenda.column_external'),
            AgendaColumnKind::None => __('program.agenda.column_none'),
        };
    }

    /** Filas de la cuadrícula entre dos minutos, como número para el CSS (sin separador de miles ni coma decimal). */
    public function rows(AgendaDay $agenda, int|float $minutes): string
    {
        $value = round($minutes / max(1, $agenda->stepMinutes), 2);

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    /** El día pedido si tiene forma de fecha válida (`Y-m-d`); si no, nulo y `BuildsAgenda` elige el primero. */
    private function requestedDay(): ?string
    {
        if (! is_string($this->date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $this->date, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $this->date : null;
    }

    private function parse(string $day): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $day, $this->event()->timezone)->startOfDay();
    }

    private function event(): Event
    {
        return $this->event ??= Event::query()->findOrFail($this->eventId);
    }
}
