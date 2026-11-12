<?php

declare(strict_types=1);

namespace App\Filament\Resources\Forms;

use App\Filament\Resources\Forms\Pages\CreateFormDefinition;
use App\Filament\Resources\Forms\Pages\EditFormDefinition;
use App\Filament\Resources\Forms\Pages\ListFormDefinitions;
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
 * Formularios de propuestas de la organización (plan 005, H-005-1). La organización
 * es el tenant del panel: la consulta pasa por el scope global de `BelongsToOrganization`
 * y un formulario de otra organización responde 404 en su URL. Es solo presentación:
 * las reglas viven en las Actions y las Policies deciden quién ve y quién edita.
 */
class FormDefinitionResource extends Resource
{
    protected static ?string $model = FormDefinition::class;

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
            'index' => ListFormDefinitions::route('/'),
            'create' => CreateFormDefinition::route('/create'),
            'edit' => EditFormDefinition::route('/{record}/edit'),
            'preview' => PreviewFormDefinition::route('/{record}/preview'),
        ];
    }
}
