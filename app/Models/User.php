<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Models\Access\AccessPosition;
use App\Models\Access\AccessRole;
use App\Models\Tan90\MasterData\Concerns\HasTan90Profile;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// Merges the React prototype's TeamMember concept into the real user
// account — one main role per account (plus optional extra roles, see
// extraRoles()), admin-managed, no self-registration.
//
// HasTan90Profile is additive: it gives $user->tan90Profile (role/plant/
// location scope, MFA status) for the Master Data and BOM/Recipe/Costing
// modules, which run their own tan90_roles-based RBAC independently of the
// `role` enum column below. A user can hold a GRN role (this enum), a
// Tan90 module role (tan90Profile), both, or neither — the two systems are
// deliberately not merged into one, see the merge plan's Phase 2 notes.
#[Fillable(['name', 'email', 'phone', 'location_id', 'role', 'access_mode', 'vendor_tier', 'description', 'sla_directive', 'super_admin', 'is_active', 'preferences', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTan90Profile, Notifiable;

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
            'role' => Role::class,
            'access_mode' => 'string',
            'super_admin' => 'boolean',
            'is_active' => 'boolean',
            'preferences' => 'array',
        ];
    }

    // The site this person works at (Master Data > Locations); null = all sites.
    public function location(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Tan90\MasterData\Location::class, 'location_id');
    }

    public function isAdmin(): bool
    {
        if ($this->role === Role::Admin) {
            return true;
        }

        // Addendum 4 consolidates the former Admin, Administrator, and
        // Super Admin tiers. The GRN enum and the advanced access/profile
        // role stores are separate, so a canonical Super Admin must also be
        // recognized by legacy role middleware and module capabilities.
        if ($this->super_admin && $this->access_mode !== 'legacy') {
            return true;
        }

        // Operational users already have an explicit enum role. Only the
        // advanced-only accounts (whose enum role is null) need the slower
        // canonical-profile lookup below.
        if ($this->role !== null) {
            return false;
        }

        if ($this->tan90Profile()->whereHas('role', fn ($query) => $query->where('code', 'ROLE-SUPER'))->exists()) {
            return true;
        }

        return $this->accessRoles()
            ->where('access_roles.code', 'ACCESS-SUPER-ADMIN')
            ->where('access_roles.status', 'active')
            ->wherePivot('status', 'active')
            ->exists();
    }

    // Access-Control-only users (Super Admin, Head, Manager, Executive
    // demo accounts) have no legacy `role` at all, so anywhere that used to
    // assume `$user->role->homeRouteName()` would exist unconditionally
    // needs this fallback instead - falls through to Workspace if they have
    // that permission, else back to login.
    public function homeRouteName(): string
    {
        if ($this->role) {
            return $this->role->homeRouteName();
        }

        if (app(\App\Services\Access\AccessControlService::class)->can($this, 'workspace.view')) {
            return 'workspace.index';
        }

        return 'login';
    }

    public function isAdvancedVendor(): bool
    {
        return $this->role === Role::Vendor && $this->vendor_tier === 'advanced';
    }

    public function accessRoles(): BelongsToMany
    {
        return $this->belongsToMany(AccessRole::class, 'access_user_roles', 'user_id', 'role_id')
            ->withPivot(['assigned_by', 'starts_at', 'expires_at', 'status'])
            ->withTimestamps();
    }

    public function accessPositions()
    {
        return $this->hasMany(AccessPosition::class);
    }

    public function extraRoleRecords(): HasMany
    {
        return $this->hasMany(UserExtraRole::class);
    }

    // Extra GRN roles on top of the main `role` (see user_extra_roles).
    /** @return array<int, Role> */
    public function extraRoles(): array
    {
        return $this->extraRoleRecords->pluck('role')
            ->filter(fn ($role) => $role instanceof Role && $role !== $this->role)
            ->values()
            ->all();
    }

    // Main role first, then extra roles. Use this for "may this person use
    // that module's screens/actions"; keep display rules (price hiding, PO
    // masking, vendor scoping) on the main `role` only.
    /** @return array<int, Role> */
    public function operationalRoles(): array
    {
        return array_values(array_filter([$this->role, ...$this->extraRoles()]));
    }

    public function holdsRole(Role $role): bool
    {
        return in_array($role, $this->operationalRoles(), true);
    }
}
