<?php

namespace App\Policies\Tan90\BomRecipeCosting;

use App\Models\User;
use App\Services\Tan90\MasterData\PermissionService;
use Illuminate\Database\Eloquent\Model;

/**
 * One policy class backs every BOM/Recipe/Costing model, mirroring Master
 * Data's Tan90MasterDataPolicy exactly. Capability checks are delegated to
 * Master Data's PermissionService (the house-wide tan90_roles/
 * tan90_role_permission matrix) rather than a second copy of the same
 * logic — this module must be installed alongside (or after) Master Data.
 * See docs/INSTALL.md.
 */
class Tan90BomRecipeCostingPolicy
{
    public function __construct(private readonly PermissionService $permissions)
    {
    }

    public function viewAny(User $user): bool
    {
        return $this->permissions->can($user, 'view');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->permissions->can($user, 'view');
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

        return $this->permissions->can($user, 'edit');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->permissions->can($user, 'delete');
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->permissions->can($user, 'delete');
    }

    public function submit(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    public function approve(User $user, Model $record): bool
    {
        return $this->permissions->can($user, 'approve');
    }

    public function reject(User $user, Model $record): bool
    {
        return $this->approve($user, $record);
    }

    public function export(User $user): bool
    {
        return $this->permissions->can($user, 'export');
    }
}
