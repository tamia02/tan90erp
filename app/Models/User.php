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
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// Merges the React prototype's TeamMember concept into the real user
// account — one role per account, admin-managed, no self-registration.
//
// HasTan90Profile is additive: it gives $user->tan90Profile (role/plant/
// location scope, MFA status) for the Master Data and BOM/Recipe/Costing
// modules, which run their own tan90_roles-based RBAC independently of the
// `role` enum column below. A user can hold a GRN role (this enum), a
// Tan90 module role (tan90Profile), both, or neither — the two systems are
// deliberately not merged into one, see the merge plan's Phase 2 notes.
#[Fillable(['name', 'email', 'phone', 'role', 'access_mode', 'vendor_tier', 'description', 'sla_directive', 'super_admin', 'is_active', 'preferences', 'password'])]
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

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
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
}
