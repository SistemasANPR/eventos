<?php

declare(strict_types=1);

namespace App\Filament\Resources\Rubrics;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Rubrics\Pages\CreateRubric;
use App\Filament\Resources\Rubrics\Pages\EditRubric;
use App\Filament\Resources\Rubrics\Schemas\RubricForm;
use App\Filament\Resources\Rubrics\Tables\RubricsTable;
use App\Models\Rubric;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;

/**
 * Rúbricas de evaluación del evento (plan 011, F4b, interfaz de H-011-1). Anidado bajo el evento, como los
 * formularios: vive en `/{tenant}/events/{event}/rubrics`; el evento de la ruta se resuelve con la consulta
 * de `EventResource` (scope de organización), así que un evento de otra organización responde 404, y una
 * rúbrica de otro evento también, porque la consulta se acota por el evento. La lista es la página del
 * evento `ManageEventRubrics`. Es solo presentación: las reglas viven en `SaveRubric` y `PublishRubric`, y
 * `RubricPolicy` (`rubrics.manage`) decide quién entra.
 */
class RubricResource extends Resource
{
    protected static ?string $model = Rubric::class;

    protected static ?string $parentResource = EventResource::class;

    // Sin él, el espacio de nombres `Rubrics` entra en la ruta y no coincide con la lista del evento.
    protected static ?string $slug = 'rubrics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    public static function getNavigationLabel(): string
    {
        return __('rubrics.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('rubrics.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('rubrics.plural');
    }

    /**
     * `RubricPolicy::create` pide además el evento, que Filament no pasa: aquí basta con poder gestionar
     * rúbricas. La autorización con el evento de la ruta la hace `SaveRubric` al guardar.
     */
    public static function getCreateAuthorizationResponse(): Response
    {
        return static::getViewAnyAuthorizationResponse();
    }

    public static function form(Schema $schema): Schema
    {
        return RubricForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RubricsTable::configure($table);
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'create' => CreateRubric::route('/create'),
            'edit' => EditRubric::route('/{record}/edit'),
        ];
    }
}
