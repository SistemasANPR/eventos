<?php

declare(strict_types=1);

namespace App\Filament\Pages\Organization;

use App\Actions\Organizations\UpdateOrganization;
use App\Http\Requests\Organizations\UpdateOrganizationRequest;
use App\Models\Organization;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Datos de la organización en su propio panel (plan 028): nombre editable e
 * identificador en solo lectura. Es presentación: guardar llama a
 * `UpdateOrganization`, que vuelve a autorizar y validar.
 */
class EditOrganizationProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return __('organizations.profile.title');
    }

    public static function canView(Model $tenant): bool
    {
        return $tenant instanceof Organization && Gate::allows('update', $tenant);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('organizations.profile.name'))
                ->required()
                ->minLength(UpdateOrganizationRequest::NAME_MIN)
                ->maxLength(UpdateOrganizationRequest::NAME_MAX),
            TextInput::make('slug')
                ->label(__('organizations.profile.slug'))
                ->helperText(__('organizations.profile.slug_help'))
                ->disabled()
                ->dehydrated(false),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Organization) {
            throw new InvalidArgumentException('El perfil del tenant debe ser una organización.');
        }

        return app(UpdateOrganization::class)->handle(auth()->user(), $record, $data);
    }
}
