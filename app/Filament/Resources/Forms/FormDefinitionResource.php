<?php

declare(strict_types=1);

namespace App\Filament\Resources\Forms;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Forms\Pages\CreateFormDefinition;
use App\Filament\Resources\Forms\Pages\EditFormDefinition;
use App\Filament\Resources\Forms\Pages\PreviewFormDefinition;
use App\Filament\Resources\Forms\Schemas\FormDefinitionForm;
use App\Filament\Resources\Forms\Tables\FormDefinitionsTable;
use App\Models\FormDefinition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Formularios de propuestas del evento (plan 005, H-005-1; anidado bajo el evento en el
 * plan 020, F1). Vive en `/{tenant}/events/{event}/form-definitions`: el evento de la ruta
 * se resuelve con la consulta de `EventResource` (scope de organización), así que un evento
 * de otra organización responde 404, y un formulario de otro evento de la misma organización
 * también, porque la consulta se acota por el evento. La lista es la página del evento
 * `ManageEventFormDefinitions`. Es solo presentación: las reglas viven en las Actions y las
 * Policies deciden quién ve y quién edita.
 */
class FormDefinitionResource extends Resource
{
    protected static ?string $model = FormDefinition::class;

    protected static ?string $parentResource = EventResource::class;

    // Sin él, el espacio de nombres `Forms` entra en la ruta (`/forms/form-definitions`) y no coincide con la lista del evento.
    protected static ?string $slug = 'form-definitions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function getNavigationLabel(): string
    {
        return __('forms.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('forms.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('forms.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return FormDefinitionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FormDefinitionsTable::configure($table);
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'create' => CreateFormDefinition::route('/create'),
            'edit' => EditFormDefinition::route('/{record}/edit'),
            'preview' => PreviewFormDefinition::route('/{record}/preview'),
        ];
    }
}
