<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationStatus;
use Database\Factories\OrganizationFactory;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant raíz (ADR-0003). Es global: no lleva `BelongsToOrganization` y se
 * declara en `TenantModels::GLOBAL_MODELS`.
 *
 * El `slug` va en la URL pública (`/{organization:slug}/...`).
 */
#[Fillable(['name', 'slug', 'status'])]
class Organization extends Model implements HasName
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** Segmentos que chocarían con rutas de la aplicación. */
    public const RESERVED_SLUGS = [
        'admin', 'up', 'login', 'register', 'api',
        'livewire', 'horizon', 'filament', 'storage', 'build', 'logout', 'password',
    ];

    /** Nombre que Filament muestra en el selector de organización. */
    public function getFilamentName(): string
    {
        return $this->name;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
        ];
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['status', 'deactivated_at'])->withTimestamps();
    }

    /** @return HasMany<Contact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }
}
