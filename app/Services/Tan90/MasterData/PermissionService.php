<?php

namespace App\Services\Tan90\MasterData;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Role-based capability checks (view/create/edit/delete/approve/export/settings)
 * backed by the editable tan90_role_permission matrix, plus plant/location
 * data-scoping for Plant User-type roles.
 *
 * This replaces the demo's hardcoded ROLE_PERMISSIONS JS object with a
 * database-backed matrix so Super Admin can actually edit it at
 * /tan90/master-data/permission-matrix, per the module's "Permission Matrix"
 * screen requirement.
 */
class PermissionService
{
    public function can(?User $user, string $permissionKey): bool
    {
        $role = $user?->tan90Profile?->role;

        return (bool) $role?->can($permissionKey);
    }

    public function isAuditor(?User $user): bool
    {
        return $user?->tan90Profile?->role?->code === 'ROLE-AUDIT';
    }

    public function isSuperAdmin(?User $user): bool
    {
        return $user?->tan90Profile?->role?->code === 'ROLE-SUPER';
    }

    /**
     * True when the user's role is data-scoped to their assigned plant/location
     * rather than seeing all records (i.e. a Plant User-type role).
     */
    public function isPlantScoped(?User $user): bool
    {
        $profile = $user?->tan90Profile;

        return (bool) ($profile && $profile->isScopedToSingleSite());
    }

    /**
     * Restrict a list query to the user's assigned plant/location when the
     * entity declares a `plant_scope_field` in its registry entry and the
     * user's role is plant-scoped. No-op for global entities (legal entities,
     * UOMs, roles, ...) and for non-scoped roles.
     */
    public function scopeQuery(Builder $query, ?User $user, array $entity): Builder
    {
        $scopeField = $entity['plant_scope_field'] ?? null;

        if (! $scopeField || ! $this->isPlantScoped($user)) {
            return $query;
        }

        $profile = $user->tan90Profile;
        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $inner) use ($scopeField, $profile, $table) {
            if ($scopeField === 'id') {
                // The entity itself *is* the plant/location (tan90_plants, tan90_locations).
                if ($profile->assigned_plant_id && $table === 'tan90_plants') {
                    $inner->where('id', $profile->assigned_plant_id);
                } elseif ($profile->assigned_location_id && $table === 'tan90_locations') {
                    $inner->where('id', $profile->assigned_location_id);
                }

                return;
            }

            if ($profile->assigned_plant_id) {
                $inner->orWhere($scopeField, $profile->assigned_plant_id);
            }
            if ($profile->assigned_location_id && $scopeField === 'tan90_location_id') {
                $inner->orWhere($scopeField, $profile->assigned_location_id);
            }
        });
    }
}
