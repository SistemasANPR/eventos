<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamMembers\Tables;

use App\Filament\Resources\TeamMembers\Actions\TeamMemberActions;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class TeamMembersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('team.resource.name'))->searchable()->sortable(),
                TextColumn::make('email')->label(__('team.resource.email'))->searchable()->sortable(),
                TextColumn::make('role')
                    ->label(__('team.resource.role'))
                    ->state(fn (User $record): string => self::roleLabel($record)),
                TextColumn::make('status')
                    ->label(__('team.resource.status'))
                    ->badge()
                    ->color(fn (User $record): string => $record->membershipStatus() === 'active' ? 'success' : 'gray')
                    ->state(fn (User $record): string => __("team.status.{$record->membershipStatus()}")),
            ])
            ->defaultSort('name')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([
                TeamMemberActions::resendInvitation(),
                TeamMemberActions::changeRole(),
                TeamMemberActions::deactivate(),
            ]);
    }

    /** Rol del integrante en la organización en contexto (los roles se cargaron con la consulta). */
    private static function roleLabel(User $member): string
    {
        $role = $member->getRoleNames()->first();

        return $role === null ? '—' : __("team.roles.{$role}");
    }
}
