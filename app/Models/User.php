<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\OrganizationStatus;
use App\Exceptions\Domain\TwoFactorCannotBeDisabled;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
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
use SensitiveParameter;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUuids, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    /** Roles de organización que exigen la verificación en dos pasos (plan 014, DA-1); `platform` es `is_platform_admin`. */
    private const TWO_FACTOR_ROLES = ['org_admin', 'organizer', 'reviewer'];

    /**
     * Igual que el DEFAULT de las columnas: un usuario recién creado no es de plataforma y no tiene
     * 2FA (modo estricto exige los atributos).
     */
    protected $attributes = [
        'is_platform_admin' => false,
        'app_authentication_secret' => null,
        'app_authentication_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
    ];

    /**
     * ¿A esta persona se le exige la 2FA? A todo usuario de plataforma y a quien tenga un rol de
     * organización con acceso a datos de autores en alguna organización (plan 014, DA-1). Los roles
     * se consultan sin filtrar por organización en contexto: la exigencia es por persona.
     */
    public function requiresTwoFactor(): bool
    {
        if ($this->is_platform_admin) {
            return true;
        }

        // `Role::users()` no filtra por organización (solo `$user->roles()` lo hace), que es lo que se necesita aquí.
        return Role::query()
            ->whereIn('name', self::TWO_FACTOR_ROLES)
            ->whereHas('users', fn (Builder $assignees) => $assignees->whereKey($this->getKey()))
            ->exists();
    }

    /** Configuró su segundo factor y lo confirmó con un código válido. */
    public function hasConfirmedTwoFactor(): bool
    {
        return filled($this->app_authentication_secret) && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Filament llama aquí al confirmar la configuración (con el secreto) y al desactivarla (con `null`).
     * Nadie desactiva su propia 2FA (CA-014-4): quitarla es `clearTwoFactor()`, que solo usan las
     * Actions de reinicio, ya autorizadas y auditadas.
     */
    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        if (blank($secret)) {
            throw new TwoFactorCannotBeDisabled;
        }

        $this->forceFill([
            'app_authentication_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    /** Quita el segundo factor y los códigos de recuperación. Solo para el reinicio autorizado (CA-014-4 y -5). */
    public function clearTwoFactor(): void
    {
        $this->forceFill([
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

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

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->canAccessPanelId($panel->getId());
    }

    /** Membresía desactivada: existe en la organización con estado `deactivated` (plan 003, DA-10). */
    public function hasDeactivatedMembershipIn(Organization $organization): bool
    {
        return $this->organizations()
            ->whereKey($organization->id)
            ->wherePivot('status', 'deactivated')
            ->exists();
    }

    /**
     * El acceso se decide por panel y se deniega por defecto: el de organizaciones
     * exige una membresía activa en una organización activa en la que la persona no
     * sea solo evaluadora; el del evaluador, una membresía activa con el rol `reviewer`;
     * el de plataforma, `is_platform_admin` y que el panel esté habilitado (planes 011,
     * 016 y 017). Un usuario de plataforma sin membresías no entra al panel de ninguna organización.
     */
    public function canAccessPanelId(string $panelId): bool
    {
        return match ($panelId) {
            'admin', 'reviewer' => $this->tenantsForPanel($panelId)->isNotEmpty(),
            'platform' => $this->is_platform_admin && (bool) config('platform.enabled'),
            default => false,
        };
    }

    /**
     * Panel al que va tras iniciar sesión (plan 017): plataforma primero si puede
     * entrar a ambos, después el de organizaciones y por último el del evaluador;
     * `null` si no puede entrar a ninguno.
     */
    public function homePanelId(): ?string
    {
        foreach (['platform', 'admin', 'reviewer'] as $panelId) {
            if ($this->canAccessPanelId($panelId)) {
                return $panelId;
            }
        }

        return null;
    }

    public function canAccessAnyPanel(): bool
    {
        return $this->homePanelId() !== null;
    }

    /**
     * Puede restablecer su contraseña: el usuario de plataforma (sin
     * membresías) o quien tiene acceso al panel. No concede acceso al panel.
     */
    public function canResetPassword(Panel $panel): bool
    {
        return $this->is_platform_admin || $this->canAccessAnyPanel();
    }

    /**
     * Organizaciones entre las que puede cambiar en el panel: solo las de
     * membresía activa (CA-001-18 y CA-001-22) y, según el panel, las que le dan
     * acceso a él (plan 011: quien solo es evaluador no entra al de organizadores).
     *
     * @return Collection<int, Organization>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->tenantsForPanel($panel->getId());
    }

    /** Sin esto, adivinar el identificador de otra organización en la URL daría acceso. */
    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof Organization || ! $this->hasActiveMembershipIn($tenant)) {
            return false;
        }

        $panelId = Filament::getCurrentPanel()?->getId();

        return $panelId === null || $this->tenantsForPanel($panelId)->contains('id', $tenant->id);
    }

    /**
     * Organizaciones activas en las que la persona usa el panel: en el de evaluadores, donde tiene el rol
     * `reviewer`; en el de organizadores, todas salvo aquellas donde su único rol es `reviewer` (el rol
     * `reviewer` no tiene ningún permiso de organizador, plan 011 DA-1, CA-011-16). En cualquier otro panel,
     * todas las de membresía activa. Los roles se consultan sin filtrar por la organización en contexto.
     *
     * @return Collection<int, Organization>
     */
    private function tenantsForPanel(string $panelId): Collection
    {
        $organizations = $this->activeOrganizations()->get();

        return match ($panelId) {
            'reviewer' => $organizations->filter(fn (Organization $organization): bool => $this->hasRoleIn($organization, ['reviewer']))->values(),
            'admin' => $organizations->reject(fn (Organization $organization): bool => $this->hasRoleIn($organization, ['reviewer'])
                && ! $this->hasRoleIn($organization, ['org_admin', 'organizer']))->values(),
            default => $organizations,
        };
    }

    /** @param  list<string>  $roles */
    private function hasRoleIn(Organization $organization, array $roles): bool
    {
        // `Role::users()` no filtra por la organización en contexto (solo `$user->roles()` lo hace).
        return Role::query()
            ->where('organization_id', $organization->id)
            ->whereIn('name', $roles)
            ->whereHas('users', fn (Builder $assignees) => $assignees->whereKey($this->getKey()))
            ->exists();
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
        // Una organización suspendida no cuenta: sus miembros pierden el panel (plan 016, CA-016-15).
        return $this->organizations()
            ->wherePivot('status', 'active')
            ->wherePivotNull('deactivated_at')
            ->where('organizations.status', OrganizationStatus::Active->value);
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
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
