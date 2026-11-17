<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Forms\CreateFormFromTemplate;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Forms\FormDefinitionResource;
use App\Filament\Support\ActionErrors;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Support\Forms\FormTemplates;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Formularios del evento (plan 020, F1): la lista que antes era un recurso de la organización.
 * La tabla y las acciones de fila vienen de `FormDefinitionResource`, anidado bajo el evento;
 * el evento sale de la ruta, así que un evento de otra organización responde 404 y no hay selector.
 * Quién ve la lista y quién crea lo decide `FormDefinitionPolicy`.
 */
class ManageEventFormDefinitions extends ManageRelatedRecords
{
    protected static string $resource = EventResource::class;

    protected static string $relationship = 'formDefinitions';

    protected static ?string $relatedResource = FormDefinitionResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function getNavigationLabel(): string
    {
        return __('forms.navigation');
    }

    /** @return array<int, mixed> */
    protected function getHeaderActions(): array
    {
        return [
            $this->fromTemplateAction(),
            CreateAction::make()
                ->url(fn (): string => FormDefinitionResource::getUrl('create', ['event' => $this->eventOfPage()]))
                ->visible(fn (): bool => auth()->user()?->can('create', FormDefinition::class) === true),
        ];
    }

    /** Crea un formulario propio, en borrador, a partir de una plantilla del sistema, en el evento de la ruta (CA-005-35, CA-020-8). */
    private function fromTemplateAction(): Action
    {
        return Action::make('fromTemplate')
            ->label(__('forms.actions.from_template'))
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->modalHeading(__('forms.actions.from_template'))
            ->modalDescription(__('forms.actions.from_template_help'))
            ->visible(fn (): bool => auth()->user()?->can('create', FormDefinition::class) === true)
            ->schema([
                Select::make('template')
                    ->label(__('forms.actions.template'))
                    ->options(self::templateOptions())
                    ->required(),
            ])
            ->action(function (array $data) {
                $event = $this->eventOfPage();

                try {
                    $form = app(CreateFormFromTemplate::class)->handle(ActionErrors::actor(), $event, (string) $data['template']);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title(ActionErrors::summary($exception))->send();

                    return null;
                }

                Notification::make()->success()->title(__('forms.actions.created_from_template'))->send();

                return redirect(FormDefinitionResource::getUrl('edit', ['event' => $event, 'record' => $form]));
            });
    }

    /** @return array<string, string> */
    private static function templateOptions(): array
    {
        $options = [];

        foreach (FormTemplates::names() as $name) {
            $options[$name] = __("forms.templates.{$name}");
        }

        return $options;
    }

    private function eventOfPage(): Event
    {
        $record = $this->getRecord();

        return $record instanceof Event ? $record : throw new LogicException('La página de formularios no tiene un evento.');
    }
}
