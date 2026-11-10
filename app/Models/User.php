<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUuids, Notifiable;

    /** Igual que el DEFAULT de la columna: un usuario recién creado no es de plataforma (modo estricto exige el atributo). */
    protected $attributes = ['is_platform_admin' => false];

    /** @return BelongsToMany<Organization, $this> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)->withPivot(['status', 'deactivated_at'])->withTimestamps();
    }

    /** Membresía activa: estado `active` y sin `deactivated_at` (CA-001-24). */
    public function hasActiveMembershipIn(Organization $organization): bool
    {
        return $this->activeOrganizations()->whereKey($organization->id)->exists();
    }

    /** Solo entra al panel quien tiene al menos una membresía activa. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->activeOrganizations()->exists();
    }

    /**
     * Organizaciones entre las que puede cambiar en el panel: solo las de
     * membresía activa (CA-001-18 y CA-001-22).
     *
     * @return Collection<int, Organization>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->activeOrganizations()->get();
    }

    /** Sin esto, adivinar el identificador de otra organización en la URL daría acceso. */
    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Organization && $this->hasActiveMembershipIn($tenant);
    }

    /**
     * Integrantes de la organización (de cualquier estado), con la membresía
     * cargada para leer su estado con `membershipStatus()`. Es la consulta del
     * equipo: un usuario de otra organización no aparece (CA-003-24).
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function membersOf(Builder $query, Organization $organization): void
    {
        $query->whereHas('organizations', fn (Builder $memberships) => $memberships->whereKey($organization->id))
            ->with(['organizations' => fn ($memberships) => $memberships->whereKey($organization->id)]);
    }

    /**
     * Integrantes con membresía activa (estado `active` y sin `deactivated_at`).
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function activeMembersOf(Builder $query, Organization $organization): void
    {
        $query->whereHas('organizations', fn (Builder $memberships) => $memberships
            ->whereKey($organization->id)
            ->where('organization_user.status', 'active')
            ->whereNull('organization_user.deactivated_at'));
    }

    /** Estado de la membresía; solo disponible si la consulta usó `membersOf` (carga la membresía). */
    public function membershipStatus(): string
    {
        $membership = $this->organizations->sole();

        return (string) $membership->getRelation('pivot')->getAttribute('status');
    }

    /** @return BelongsToMany<Organization, $this> */
    private function activeOrganizations(): BelongsToMany
    {
        return $this->organizations()
            ->wherePivot('status', 'active')
            ->wherePivotNull('deactivated_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }
}
