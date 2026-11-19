<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events;

use App\Actions\Program\CreateProgramCategory;
use App\Enums\ProgramCategoryKind;
use App\Filament\Support\ActionErrors;
use App\Models\Event;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Accesos a las categorías del programa desde Ponentes y Actividades, donde se usan: el botón
 * «Categorías» de la cabecera (con su conteo) y el alta en línea desde el selector del formulario.
 * Solo presentan: la autorización y las reglas siguen en las Policies y en `CreateProgramCategory`,
 * así que ocultar el botón o el «Crear» no autoriza nada.
 */
final class ProgramCategoryShortcuts
{
    /** Botón de la cabecera que lleva a la página del catálogo de categorías. */
    public static function button(Event $event, ProgramCategoryKind $kind): Action
    {
        $model = $kind->modelClass();

        return Action::make('categories')
            ->label(__('program.categories.button'))
            ->icon(Heroicon::OutlinedTag)
            ->color('gray')
            ->outlined()
            ->badge(fn (): int => $model::query()->where('event_id', $event->id)->count())
            ->url(fn (): string => EventResource::getUrl(self::catalogPage($kind), ['record' => $event]))
            ->visible(fn (): bool => Gate::allows('viewAny', $model));
    }

    /** Página del catálogo (ruta del recurso) de cada tipo. */
    public static function catalogPage(ProgramCategoryKind $kind): string
    {
        return match ($kind) {
            ProgramCategoryKind::Speaker => 'speaker-categories',
            ProgramCategoryKind::Activity => 'activity-categories',
        };
    }

    /** Añade «Crear categoría» al selector, solo a quien puede crearlas en este evento. */
    public static function withInlineCreate(Select $select, Event $event, ProgramCategoryKind $kind): Select
    {
        return $select
            ->createOptionForm(fn (): ?array => Gate::allows('create', [$kind->modelClass(), $event])
                ? [TextInput::make('name')->label(__('program.categories.name'))->required()->maxLength(100)]
                : null)
            ->createOptionModalHeading(__('program.categories.create'))
            ->createOptionUsing(function (array $data) use ($event, $kind): string {
                try {
                    $category = app(CreateProgramCategory::class)->handle(ActionErrors::actor(), $event, $kind, $data);
                } catch (ValidationException $exception) {
                    // Un nombre repetido o inválido avisa y deja abierto el selector, sin crear nada.
                    Notification::make()->title(__('program.categories.failed'))->body(ActionErrors::summary($exception))->danger()->send();

                    throw new Halt;
                }

                return (string) $category->getKey();
            });
    }
}
