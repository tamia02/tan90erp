<?php

namespace App\Http\Controllers\AccessControl;

use App\Http\Controllers\Controller;
use App\Models\Access\AccessAuditLog;
use App\Models\Access\AccessPermission;
use App\Models\Access\AccessRole;
use App\Models\Access\AccessVertical;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;

class AccessRoleController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.roles.view'), 403);

        $query = AccessRole::with(['vertical', 'parent', 'users'])->withCount(['permissions', 'users']);

        if ($search = $request->string('search')->toString()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")->orWhere('label', 'like', "%{$search}%"));
        }
        if ($request->filled('level')) {
            $query->where('level', $request->integer('level'));
        }
        if ($request->filled('vertical_id')) {
            $query->where('vertical_id', $request->integer('vertical_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $roles = $query->orderBy('level')->orderBy('name')->paginate(12)->withQueryString();

        return view('access-control.roles-index', [
            'roles' => $roles,
            'verticals' => AccessVertical::orderBy('name')->get(),
            'canManage' => fn (AccessRole $role) => $this->access->canManageRole($request->user(), $role),
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.roles.manage'), 403);

        return view('access-control.roles-form', [
            'role' => new AccessRole(['status' => 'active', 'level' => AccessRole::LEVEL_EXECUTIVE]),
            'permissions' => AccessPermission::orderBy('module')->orderBy('screen')->orderBy('sort_order')->get(),
            'verticals' => AccessVertical::orderBy('name')->get(),
            'parents' => AccessRole::orderBy('level')->orderBy('name')->get(),
            'selected' => collect(),
            'activity' => collect(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.roles.manage'), 403);
        $data = $this->validated($request);
        $role = AccessRole::make($data);
        abort_unless($this->access->canManageRole($request->user(), $role), 403, 'Hierarchy rule blocks this role.');
        $role->created_by = $request->user()->id;
        $role->save();
        $this->syncPermissions($request, $role);
        $this->access->audit($request->user(), 'role.created', $role, null, $role->toArray());

        return redirect()->route('access.roles.edit', $role)->with('status', 'Role created.');
    }

    public function edit(Request $request, AccessRole $role)
    {
        abort_unless($this->access->canManageRole($request->user(), $role) || $this->access->can($request->user(), 'access.roles.view'), 403);

        return view('access-control.roles-form', [
            'role' => $role->load(['permissions', 'vertical', 'parent', 'users']),
            'permissions' => AccessPermission::orderBy('module')->orderBy('screen')->orderBy('sort_order')->get(),
            'verticals' => AccessVertical::orderBy('name')->get(),
            'parents' => AccessRole::whereKeyNot($role->id)->orderBy('level')->orderBy('name')->get(),
            'selected' => $role->permissions->keyBy('id'),
            'activity' => AccessAuditLog::where('target_type', $role::class)->where('target_id', $role->id)->latest('created_at')->limit(20)->get(),
        ]);
    }

    public function update(Request $request, AccessRole $role)
    {
        abort_unless($this->access->canManageRole($request->user(), $role), 403);
        abort_if($role->is_system && $request->string('status') === 'deleted', 403, 'System roles cannot be deleted.');
        $before = $role->toArray();
        $role->update($this->validated($request));
        $this->syncPermissions($request, $role);
        $role->users->each(fn ($user) => $this->access->flushUser($user));
        $this->access->audit($request->user(), 'role.updated', $role, $before, $role->fresh()->toArray());

        return back()->with('status', 'Role updated.');
    }

    public function clone(Request $request, AccessRole $role)
    {
        abort_unless($this->access->canManageRole($request->user(), $role), 403);
        $copy = $role->replicate(['code', 'is_system']);
        $copy->code = $role->code.'-COPY-'.now()->format('His');
        $copy->name = $role->name.' Copy';
        $copy->is_system = false;
        abort_unless($this->access->canManageRole($request->user(), $copy), 403);
        $copy->save();
        foreach ($role->permissions as $permission) {
            $copy->permissions()->attach($permission->id, $permission->pivot->only(['allowed', 'delegable', 'scope_type', 'scope_json']));
        }
        $this->access->audit($request->user(), 'role.cloned', $copy, ['source_role_id' => $role->id], $copy->toArray());

        return redirect()->route('access.roles.edit', $copy)->with('status', 'Role cloned.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'label' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255'],
            'level' => ['required', 'integer', 'between:1,4'],
            'vertical_id' => ['nullable', 'exists:access_verticals,id'],
            'parent_role_id' => ['nullable', 'exists:access_roles,id'],
            'status' => ['required', 'in:active,inactive'],
            'description' => ['nullable', 'string'],
        ]);
    }

    private function syncPermissions(Request $request, AccessRole $role): void
    {
        $sync = [];
        foreach ($request->input('permissions', []) as $permissionId => $payload) {
            $key = AccessPermission::find($permissionId)?->key;
            if (! $key || ! $this->access->canDelegate($request->user(), $key, ['scope_type' => $payload['scope_type'] ?? 'self'])) {
                continue;
            }

            $effect = $payload['effect'] ?? 'inherit';
            $sync[$permissionId] = [
                'allowed' => $effect === 'allow',
                'effect' => $effect,
                'delegable' => isset($payload['delegable']),
                'locked' => isset($payload['locked']),
                'scope_type' => $payload['scope_type'] ?? 'self',
                'max_scope_type' => $payload['max_scope_type'] ?? $payload['scope_type'] ?? 'self',
                'field_mode' => $payload['field_mode'] ?? 'full',
                'scope_json' => null,
            ];
        }
        $role->permissions()->sync($sync);
    }
}
