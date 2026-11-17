<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Organizations\Pages;

use App\Actions\Organizations\ReactivateOrganization;
use App\Actions\Organizations\SuspendOrganization;
use App\Enums\OrganizationStatus;
use App\Filament\Platform\Resources\Organizations\OrganizationResource;
use App\Filament\Support\ActionErrors;
use App\Http\Requests\Platform\SuspendOrganizationRequest;
use App\Models\Organization;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/** Detalle de una organización: metadatos, administradores y auditoría reciente, con suspender y reactivar. */
class ViewOrganization extends ViewRecord
{
    protected static string $resource = OrganizationResource::class;

    public function getTitle(): string
    {
        $organization = $this->getRecord();

        return $organization instanceof Organization ? $organization->name : parent::getTitle();
    }

    /** @return array<int, mixed> */
    protected function getHeaderActions(): array
    {
        return [$this->suspendAction(), $this->reactivateAction()];
    }

    private function suspendAction(): Action
    {
        return Action::make('suspend')
            ->label(__('platform.organizations.suspend'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalHeading(__('platform.organizations.suspend'))
            ->modalDescription(__('platform.organizations.suspend_help'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('platform.organizations.fields.reason'))
                    ->rules(SuspendOrganizationRequest::rulesFor()['reason'])
                    ->required()
                    ->rows(3),
            ])
            ->visible(fn (Organization $record): bool => $record->status === OrganizationStatus::Active && Gate::allows('suspend', $record))
            ->action(function (Organization $record, array $data): void {
                app(SuspendOrganization::class)->handle(ActionErrors::actor(), $record, $data);
                Notification::make()->title(__('platform.organizations.suspended'))->success()->send();
            });
    }

    private function reactivateAction(): Action
    {
        return Action::make('reactivate')
            ->label(__('platform.organizations.reactivate'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('platform.organizations.reactivate_help'))
            ->visible(fn (Organization $record): bool => $record->status === OrganizationStatus::Suspended && Gate::allows('reactivate', $record))
            ->action(function (Organization $record): void {
                app(ReactivateOrganization::class)->handle(ActionErrors::actor(), $record);
                Notification::make()->title(__('platform.organizations.reactivated'))->success()->send();
            });
    }
}
