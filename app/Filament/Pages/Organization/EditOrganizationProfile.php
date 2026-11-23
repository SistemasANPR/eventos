<?php

declare(strict_types=1);

namespace App\Filament\Pages\Organization;

use App\Actions\Organizations\UpdateOrganization;
use App\Http\Requests\Organizations\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Rules\OrganizationSlug;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Datos de la organización en su propio panel (plan 028): nombre e identificador
 * de la URL. Cambiar el identificador deja sin efecto las URL anteriores, y el
 * formulario lo advierte. Es presentación: guardar llama a `UpdateOrganization`,
 * que vuelve a autorizar y validar; las mismas reglas se repiten aquí para que
 * los errores salgan junto al campo.
 */
class EditOrganizationProfile extends EditTenantProfile
{
    private bool $slugChanged = false;

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
                ->required()
                ->rules([new OrganizationSlug])
                ->unique(Organization::class, 'slug', ignoreRecord: true),
            Callout::make(__('organizations.profile.slug_warning'))
                ->description(__('organizations.profile.slug_warning_detail'))
                ->warning(),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Organization) {
            throw new InvalidArgumentException('El perfil del tenant debe ser una organización.');
        }

        $this->slugChanged = ($data['slug'] ?? $record->slug) !== $record->slug;

        $updated = app(UpdateOrganization::class)->handle(auth()->user(), $record, $data);
        $record->setRawAttributes($updated->getAttributes(), true);

        return $record;
    }

    /** Con otro identificador, la URL de esta página cambia: se va a la nueva. */
    protected function getRedirectUrl(): ?string
    {
        return $this->slugChanged ? static::getUrl(tenant: $this->tenant) : null;
    }
}
