<?php

declare(strict_types=1);

namespace App\Filament\Resources\Forms\Pages;

use App\Actions\Forms\CreateFormFromTemplate;
use App\Filament\Resources\Forms\FormDefinitionResource;
use App\Filament\Resources\Forms\Schemas\FormDefinitionForm;
use App\Filament\Support\ActionErrors;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Support\Forms\FormTemplates;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

class ListFormDefinitions extends ListRecords
{
    protected static string $resource = FormDefinitionResource::class;

    /** @return array<int, mixed> */
    protected function getHeaderActions(): array
    {
        return [
            $this->fromTemplateAction(),
            CreateAction::make(),
        ];
    }

    /** Crea un formulario propio, en borrador, a partir de una plantilla del sistema (CA-005-35). */
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
                Select::make('event_id')
                    ->label(__('forms.fields.event'))
                    ->options(fn (): array => FormDefinitionForm::eventOptions())
                    ->required()
                    ->searchable(),
            ])
            ->action(function (array $data) {
                $event = Event::query()->findOrFail($data['event_id']);

                try {
                    $form = app(CreateFormFromTemplate::class)->handle(ActionErrors::actor(), $event, (string) $data['template']);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title(ActionErrors::summary($exception))->send();

                    return null;
                }

                Notification::make()->success()->title(__('forms.actions.created_from_template'))->send();

                return redirect(FormDefinitionResource::getUrl('edit', ['record' => $form]));
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
}
