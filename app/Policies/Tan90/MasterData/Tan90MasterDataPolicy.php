<?php

namespace App\Policies\Tan90\MasterData;

use App\Models\User;
use App\Services\Tan90\MasterData\EntityRegistry;
use App\Services\Tan90\MasterData\PermissionService;
use Illuminate\Database\Eloquent\Model;

/**
 * One policy class backs every Tan90 master-data model (see
 * AuthServiceProvider::$policies) - capability checks come from the editable
 * permission matrix (PermissionService::can), and record-level access is
 * additionally narrowed by plant/location scope for Plant User-type roles.
 *
 * Auditor role: only 'view'/'export' are ever granted true in the seeded
 * permission matrix, so viewAny/view/export pass while create/update/delete/
 * approve fall through to false here automatically - no special-casing needed.
 */
class Tan90MasterDataPolicy
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly EntityRegistry $registry,
    ) {
    }

    public function viewAny(User $user): bool
    {
        return $this->permissions->can($user, 'view');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->permissions->can($user, 'view') && $this->inScope($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can($user, 'create');
    }

    public function update(User $user, Model $record): bool
    {
        if ($record->getAttribute('status') === 'archived') {
            return false;
        }

        return $this->permissions->can($user, 'edit') && $this->inScope($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->permissions->can($user, 'delete') && $this->inScope($user, $record);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->permissions->can($user, 'delete') && $this->inScope($user, $record);
    }

    public function submit(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    public function approve(User $user, Model $record): bool
    {
        return $this->permissions->can($user, 'approve') && $this->inScope($user, $record);
    }

    public function reject(User $user, Model $record): bool
    {
        return $this->approve($user, $record);
    }

    public function export(User $user): bool
    {
        return $this->permissions->can($user, 'export');
    }

    public function settings(User $user): bool
    {
        return $this->permissions->can($user, 'settings');
    }

    private function inScope(User $user, Model $record): bool
    {
        if (! $this->permissions->isPlantScoped($user)) {
            return true;
        }

        $slug = $this->registry->slugForModel($record);
        $entity = $slug ? $this->registry->get($slug) : null;
        $scopeField = $entity['plant_scope_field'] ?? null;

        if (! $scopeField) {
            return true; // entity isn't plant/location scoped (e.g. UOMs, roles)
        }

        $profile = $user->tan90Profile;
        $table = $record->getTable();

        if ($scopeField === 'id') {
            if ($table === 'tan90_plants') {
                return (int) $record->getKey() === (int) $profile->assigned_plant_id;
            }
            if ($table === 'tan90_locations') {
                return (int) $record->getKey() === (int) $profile->assigned_location_id;
            }

            return true;
        }

        $value = $record->getAttribute($scopeField);

        return ($profile->assigned_plant_id && $value == $profile->assigned_plant_id)
            || ($profile->assigned_location_id && $scopeField === 'tan90_location_id' && $value == $profile->assigned_location_id);
    }
}
