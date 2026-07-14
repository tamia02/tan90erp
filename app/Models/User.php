<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Models\Tan90\MasterData\Concerns\HasTan90Profile;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
#[Fillable(['name', 'email', 'phone', 'role', 'vendor_tier', 'description', 'sla_directive', 'super_admin', 'is_active', 'preferences', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasTan90Profile;

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
            'super_admin' => 'boolean',
            'is_active' => 'boolean',
            'preferences' => 'array',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isAdvancedVendor(): bool
    {
        return $this->role === Role::Vendor && $this->vendor_tier === 'advanced';
    }
}
