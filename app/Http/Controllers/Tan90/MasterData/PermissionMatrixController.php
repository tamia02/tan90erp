<?php

namespace App\Http\Controllers\Tan90\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Tan90\MasterData\Permission;
use App\Models\Tan90\MasterData\Role;
use App\Services\Tan90\MasterData\AuditLogger;
use App\Services\Tan90\MasterData\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Backs the "GET/POST permission matrix" special route. Editing is
 * restricted to the 'settings' capability (Super Admin only in the seeded
 * matrix), matching the demo's `can('settings')` gate on this screen.
 */
class PermissionMatrixController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function edit(Request $request)
    {
        abort_unless($this->permissions->can($request->user(), 'view'), 403);

        return view('tan90.master-data.permission-matrix', [
            'roles' => Role::active()->orderBy('name')->with('permissions')->get(),
            'permissions' => Permission::orderBy('id')->get(),
            'canEdit' => $this->permissions->can($request->user(), 'settings'),
        ]);
    }

    public function update(Request $request)
    {
        abort_unless($this->permissions->can($request->user(), 'settings'), 403);

        $matrix = $request->input('matrix', []); // [role_id => [permission_id => '1']]

        DB::transaction(function () use ($matrix) {
            foreach ($matrix as $roleId => $permissionValues) {
                $role = Role::findOrFail($roleId);
                $sync = [];
                foreach (Permission::pluck('id') as $permissionId) {
                    $sync[$permissionId] = ['allowed' => (bool) ($permissionValues[$permissionId] ?? false)];
                }
                $role->permissions()->sync($sync);
            }
        });

        $this->auditLogger->logSystem('UPDATE', 'Permission Matrix', 'Updated role permission matrix.');

        return back()->with('status', 'Permission matrix saved.');
    }
}
