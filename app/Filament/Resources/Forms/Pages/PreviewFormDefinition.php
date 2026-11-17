<?php

declare(strict_types=1);

namespace App\Filament\Resources\Forms\Pages;

use App\Filament\Resources\Forms\FormDefinitionResource;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Support\Forms\FormPreviewProps;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use LogicException;

/**
 * Vista previa del formulario (plan 005, H-005-2, CA-005-12 a CA-005-15): monta el mismo
 * `FormRenderer` de React que usará el formulario público, con el envío desactivado.
 * Es una excepción acotada a ADR-0004. La página es del panel: exige sesión, la
 * organización del tenant limita el formulario (otra organización responde 404) y la
 * Policy `view` decide (`forms.view` o `forms.manage`). Nunca crea una propuesta.
 */
class PreviewFormDefinition extends ViewRecord
{
    protected static string $resource = FormDefinitionResource::class;

    /** Idioma de la vista previa; se valida contra los del evento al armar los datos. */
    public string $locale = '';

    public function getTitle(): string
    {
        return __('forms.preview.title', ['name' => $this->formDefinition()->name]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.forms.preview')->viewData(fn (): array => $this->viewData()),
        ]);
    }

    /** @return array<string, mixed> */
    private function viewData(): array
    {
        $form = $this->formDefinition();
        $event = Event::query()->findOrFail($form->event_id);
        $props = app(FormPreviewProps::class)->handle($form, $event, $this->locale);

        return [
            'props' => $props,
            'locales' => $event->locales,
            'locale' => $props['locale'],
        ];
    }

    private function formDefinition(): FormDefinition
    {
        $record = $this->getRecord();

        return $record instanceof FormDefinition ? $record : throw new LogicException('La vista previa no tiene un formulario.');
    }
}
