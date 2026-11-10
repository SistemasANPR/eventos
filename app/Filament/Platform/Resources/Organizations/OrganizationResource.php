<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Organizations;

use App\Filament\Platform\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Platform\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Platform\Support\PlatformQueries;
use App\Models\Organization;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Organizaciones desde el panel de plataforma (plan 016, H-016-2): lista y
 * detalle de metadatos, alta y suspensión. `Organization` es un modelo global; la
 * cuenta de eventos pasa por el salto de filtro auditado. No hay borrado ni
 * edición: quién puede qué lo decide `OrganizationPolicy`.
 */
class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('platform.organizations.plural');
    }

    public static function getModelLabel(): string
    {
        return __('platform.organizations.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('platform.organizations.plural');
    }

    /** @return Builder<Organization> */
    public static function getEloquentQuery(): Builder
    {
        return Organization::query()
            ->withCount(['users as active_members_count' => fn (Builder $members) => $members
                ->where('organization_user.status', 'active')
                ->whereNull('organization_user.deactivated_at')])
            ->addSelect(['events_count' => PlatformQueries::eventsOfOrganization()]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('platform.organizations.columns.name'))->searchable()->weight('medium'),
                TextColumn::make('slug')->label(__('platform.organizations.columns.slug'))->searchable(),
                TextColumn::make('status')->label(__('platform.organizations.columns.status'))->badge(),
                TextColumn::make('active_members_count')->label(__('platform.organizations.columns.members')),
                TextColumn::make('events_count')->label(__('platform.organizations.columns.events')),
            ])
            ->defaultSort('name')
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordUrl(fn (Organization $record): string => static::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('platform.organizations.empty'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label(__('platform.organizations.columns.name')),
                    TextEntry::make('slug')->label(__('platform.organizations.columns.slug')),
                    TextEntry::make('status')->label(__('platform.organizations.columns.status'))->badge(),
                    TextEntry::make('active_members_count')->label(__('platform.organizations.columns.members')),
                    TextEntry::make('events_count')->label(__('platform.organizations.columns.events')),
                ]),
            Section::make(__('platform.organizations.admins'))
                ->schema([
                    TextEntry::make('admins')
                        ->hiddenLabel()
                        ->state(fn (Organization $record): array => PlatformQueries::administratorsOf($record))
                        ->listWithLineBreaks()
                        ->placeholder('—'),
                ]),
            Section::make(__('platform.organizations.recent_audit'))
                ->schema([
                    TextEntry::make('recent_audit')
                        ->hiddenLabel()
                        ->state(fn (Organization $record): array => PlatformQueries::recentAuditOf($record))
                        ->listWithLineBreaks()
                        ->placeholder('—'),
                ]),
        ]);
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'view' => ViewOrganization::route('/{record}'),
        ];
    }
}
