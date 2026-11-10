<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamMembers;

use App\Filament\Resources\TeamMembers\Pages\ListTeamMembers;
use App\Filament\Resources\TeamMembers\Tables\TeamMembersTable;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Equipo de la organización (plan 003, H-003-3). El modelo es `User`, que no
 * lleva `organization_id`: la consulta se acota por la membresía en la
 * organización en contexto (scope `membersOf`), así que un usuario de otra
 * organización no se lista ni se resuelve por identificador (CA-003-24). Quién
 * puede verlo y operarlo lo decide `MembershipPolicy`; las acciones llaman a
 * las Actions, que vuelven a autorizar.
 */
class TeamMemberResource extends Resource
{
    protected static ?string $model = User::class;

    // `User` no tiene columna de organización: el acotado se hace en `getEloquentQuery()`.
    protected static bool $isScopedToTenant = false;

    protected static ?string $slug = 'team';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function getNavigationLabel(): string
    {
        return __('team.resource.plural');
    }

    public static function getModelLabel(): string
    {
        return __('team.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('team.resource.plural');
    }

    /** @return Builder<User> */
    public static function getEloquentQuery(): Builder
    {
        return User::query()->membersOf(CurrentOrganization::get())->with('roles');
    }

    public static function table(Table $table): Table
    {
        return TeamMembersTable::configure($table);
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ListTeamMembers::route('/'),
        ];
    }
}
