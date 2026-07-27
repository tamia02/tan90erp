<?php

namespace App\Http\Controllers\AccessControl;

use App\Http\Controllers\Controller;
use App\Models\Access\AccessPermission;
use App\Models\Access\AccessRole;
use App\Models\Access\AccessTeam;
use App\Models\Access\AccessUserPermissionOverride;
use App\Models\Access\AccessVertical;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccessPeopleController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.people.view'), 403);
        $query = User::with('accessRoles.vertical')->withCount('accessRoles');
        if ($search = $request->string('search')->toString()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
        }
        if ($request->filled('role_id')) {
            $query->whereHas('accessRoles', fn ($q) => $q->where('access_roles.id', $request->integer('role_id')));
        }

        return view('access-control.people-index', [
            'users' => $query->orderBy('name')->paginate(12)->withQueryString(),
            'roles' => AccessRole::orderBy('level')->orderBy('name')->get(),
            'verticals' => AccessVertical::orderBy('name')->get(),
            'teams' => AccessTeam::with('vertical')->orderBy('name')->get(),
            'canManageUser' => fn (User $target) => $this->access->canManageUser($request->user(), $target),
        ]);
    }

    public function show(Request $request, User $user)
    {
        abort_unless($this->access->canManageUser($request->user(), $user) || $request->user()->is($user), 403);

        return view('access-control.people-show', [
            'person' => $user->load(['accessRoles', 'accessPositions']),
            'permissions' => AccessPermission::orderBy('module')->orderBy('screen')->orderBy('sort_order')->get(),
            'overrides' => AccessUserPermissionOverride::with('permission')->where('user_id', $user->id)->latest()->get(),
        ]);
    }

    public function assign(Request $request, User $user)
    {
        abort_unless($this->access->canManageUser($request->user(), $user), 403);
        $data = $request->validate([
            'role_id' => ['required', 'exists:access_roles,id'],
            'expires_at' => ['nullable', 'date'],
        ]);
        $role = AccessRole::findOrFail($data['role_id']);
        abort_unless($this->access->canManageRole($request->user(), $role), 403);
        DB::table('access_user_roles')->updateOrInsert(
            ['user_id' => $user->id, 'role_id' => $role->id],
            ['assigned_by' => $request->user()->id, 'starts_at' => now(), 'expires_at' => $data['expires_at'] ?? null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
        );
        $this->access->flushUser($user);
        $this->access->audit($request->user(), 'user.role.assigned', $user, null, ['role_id' => $role->id]);

        return back()->with('status', 'Role assigned.');
    }

    public function grantOverride(Request $request, User $user)
    {
        abort_unless($this->access->canManageUser($request->user(), $user), 403);
        $data = $request->validate([
            'permission_id' => ['required', 'exists:access_permissions,id'],
            'effect' => ['required', 'in:allow,deny'],
            'scope_type' => ['required', 'string'],
            'field_mode' => ['nullable', 'in:hidden,visible,readonly,editable,masked'],
            'expires_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:5'],
        ]);
        $permission = AccessPermission::findOrFail($data['permission_id']);
        abort_unless($data['effect'] === 'deny' || $this->access->canDelegate($request->user(), $permission->key, ['scope_type' => $data['scope_type']]), 403, 'Grant exceeds your delegation ceiling.');
        $override = AccessUserPermissionOverride::updateOrCreate(
            ['user_id' => $user->id, 'permission_id' => $permission->id],
            $data + ['granted_by' => $request->user()->id, 'allowed' => $data['effect'] === 'allow', 'starts_at' => now(), 'status' => 'active']
        );
        $this->access->flushUser($user);
        $this->access->audit($request->user(), 'user.permission.override', $override, null, $override->toArray());

        return back()->with('status', 'Extra access saved.');
    }
}
