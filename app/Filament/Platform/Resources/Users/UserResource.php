<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Users;

use App\Filament\Platform\Resources\Users\Pages\ListUsers;
use App\Filament\Platform\Resources\Users\Pages\ViewUser;
use App\Filament\Platform\Support\PlatformQueries;
use App\Filament\Platform\Support\ReadOnlyResource;
use App\Models\User;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Usuarios de todas las organizaciones, de solo lectura (plan 016, H-016-3):
 * nombre, correo, si es de plataforma y a qué organizaciones pertenece. Nunca
 * muestra contraseña, token ni secretos; `is_platform_admin` no se puede cambiar
 * desde aquí (plan 001, DA-8). `User` es un modelo global.
 */
class UserResource extends ReadOnlyResource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('platform.users.plural');
    }

    public static function getModelLabel(): string
    {
        return __('platform.users.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('platform.users.plural');
    }

    /** @return Builder<User> */
    public static function getEloquentQuery(): Builder
    {
        return User::query()->withCount(['organizations as active_organizations_count' => fn (Builder $memberships) => $memberships
            ->where('organization_user.status', 'active')
            ->whereNull('organization_user.deactivated_at')]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('platform.users.columns.name'))->searchable()->weight('medium'),
                TextColumn::make('email')->label(__('platform.users.columns.email'))->searchable(),
                IconColumn::make('is_platform_admin')->label(__('platform.users.columns.platform'))->boolean(),
                TextColumn::make('active_organizations_count')->label(__('platform.users.columns.organizations')),
            ])
            ->defaultSort('name')
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordUrl(fn (User $record): string => static::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('platform.users.empty'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label(__('platform.users.columns.name')),
                    TextEntry::make('email')->label(__('platform.users.columns.email')),
                    TextEntry::make('is_platform_admin')
                        ->label(__('platform.users.columns.platform'))
                        ->state(fn (User $record): string => __($record->is_platform_admin ? 'platform.yes' : 'platform.no')),
                ]),
            Section::make(__('platform.users.memberships'))
                ->schema([
                    TextEntry::make('memberships')
                        ->hiddenLabel()
                        ->state(fn (User $record): array => PlatformQueries::membershipsOf($record))
                        ->listWithLineBreaks()
                        ->placeholder('—'),
                ]),
        ]);
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}
