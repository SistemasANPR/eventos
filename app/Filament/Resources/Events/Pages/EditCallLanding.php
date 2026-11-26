<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Landings\CreateCallLanding;
use App\Actions\Landings\ResolveLandingBlocks;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Filament\Support\ActionErrors;
use App\Models\CallForSubmissions;
use App\Models\EventLanding;
use App\Support\Landings\LandingEditorProps;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * Editor visual de la landing PROPIA de una convocatoria (plan 033): la misma pantalla y el mismo editor que el de
 * la landing del evento, pero sobre la landing de esa convocatoria (`event_landings.call_id`). Hereda el guardado,
 * la publicación, el descarte y la subida de imágenes de `EditEventLanding`; solo cambia qué landing lee, cómo la
 * crea (con la plantilla de convocatoria, la primera vez que se abre) y los datos de la convocatoria que ofrece
 * al lienzo. Quién entra lo decide `EventPolicy::viewLanding` (`events.manage`); una convocatoria de otro evento u
 * organización responde 404.
 */
class EditCallLanding extends EditEventLanding
{
    /** Identificador de la convocatoria de la ruta: no lo puede cambiar el cliente entre llamadas. */
    #[Locked]
    public string $callId = '';

    public function mount(int|string $record, string $call = ''): void
    {
        $this->callId = $call;

        // Antes de crear nada: sin permiso, 403; una convocatoria que no es de este evento (o un identificador que no es uno), 404.
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();
        $this->callRecord();

        parent::mount($record);
    }

    public function getTitle(): string|Htmlable
    {
        return __('landings.editor.call_title', [
            'call' => (string) $this->callRecord()->getTranslation('title', $this->event()->default_locale),
            'name' => EventsTable::displayName($this->event()),
        ]);
    }

    protected function ensureLanding(): void
    {
        app(CreateCallLanding::class)->handle(ActionErrors::actor(), $this->callRecord());
    }

    /** @return array<string, mixed> */
    protected function editorProps(): array
    {
        return LandingEditorProps::from($this->event(), $this->organization(), $this->landing(), app(ResolveLandingBlocks::class), $this->callRecord());
    }

    protected function landing(): EventLanding
    {
        return EventLanding::query()->where('call_id', $this->callRecord()->id)->firstOrFail();
    }

    private function callRecord(): CallForSubmissions
    {
        abort_unless(Str::isUuid($this->callId), 404);

        return CallForSubmissions::query()->where('event_id', $this->event()->id)->whereKey($this->callId)->firstOrFail();
    }
}
