<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Organizations\Pages;

use App\Actions\Organizations\CreateOrganization;
use App\Exceptions\Domain\OrganizationAlreadyExists;
use App\Filament\Platform\Resources\Organizations\OrganizationResource;
use App\Http\Requests\Platform\CreateOrganizationRequest;
use App\Models\Organization;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/** Lista de organizaciones, con el alta de una organización y su primer administrador. */
class ListOrganizations extends ListRecords
{
    protected static string $resource = OrganizationResource::class;

    /** @return array<int, mixed> */
    protected function getHeaderActions(): array
    {
        return [$this->createOrganizationAction()];
    }

    private function createOrganizationAction(): Action
    {
        $rules = CreateOrganizationRequest::rulesFor();

        return Action::make('createOrganization')
            ->label(__('platform.organizations.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading(__('platform.organizations.create'))
            ->visible(fn (): bool => Gate::allows('create', Organization::class))
            ->schema([
                TextInput::make('slug')
                    ->label(__('platform.organizations.fields.slug'))
                    ->helperText(__('platform.organizations.fields.slug_help'))
                    ->rules($rules['slug'])
                    ->required(),
                TextInput::make('name')
                    ->label(__('platform.organizations.fields.name'))
                    ->rules($rules['name'])
                    ->required(),
                TextInput::make('admin_name')
                    ->label(__('platform.organizations.fields.admin_name'))
                    ->rules($rules['admin_name'])
                    ->required(),
                TextInput::make('admin_email')
                    ->label(__('platform.organizations.fields.admin_email'))
                    ->helperText(__('platform.organizations.fields.admin_email_help'))
                    ->rules($rules['email'])
                    ->required(),
            ])
            ->action(function (array $data, Action $action): void {
                Gate::authorize('create', Organization::class);

                try {
                    app(CreateOrganization::class)->handle(
                        (string) $data['slug'],
                        (string) $data['name'],
                        (string) $data['admin_email'],
                        (string) $data['admin_name'],
                    );
                } catch (OrganizationAlreadyExists) {
                    Notification::make()->title(__('platform.organizations.already_exists'))->danger()->send();
                    $action->halt();
                }

                Notification::make()->title(__('platform.organizations.created'))->success()->send();
            });
    }
}
