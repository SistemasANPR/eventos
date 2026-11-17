<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamMembers\Pages;

use App\Filament\Resources\TeamMembers\Actions\TeamMemberActions;
use App\Filament\Resources\TeamMembers\TeamMemberResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListTeamMembers extends ListRecords
{
    protected static string $resource = TeamMemberResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [TeamMemberActions::invite()];
    }
}
