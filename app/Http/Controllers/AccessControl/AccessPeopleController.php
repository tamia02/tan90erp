<?php

namespace App\Http\Controllers\AccessControl;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Access\AccessPermission;
use App\Models\Access\AccessRole;
use App\Models\Access\AccessTeam;
use App\Models\Access\AccessUserPermissionOverride;
use App\Models\Access\AccessVertical;
use App\Models\Tan90\MasterData\Location;
use App\Models\User;
use App\Models\UserExtraRole;
use App\Services\Access\AccessControlService;
use App\Services\Access\RolePermissionMatrix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccessPeopleController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.people.view'), 403);
        $query = User::with(['accessRoles.vertical', 'extraRoleRecords'])->withCount('accessRoles');
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

    public function create(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.people.view'), 403);

        return redirect()->route('admin.users')->with('status', 'Create accounts in Users. Manage roles and reporting from the user row.');
    }

    /**
     * Creates a brand-new person in one step: the account, their role, who
     * they report to, and - if this is a Head/Manager type role - which
     * existing people now report to them. That last part is what makes
     * their combined dashboard appear automatically: no one builds it by
     * hand, it's just the union of whatever widgets those assigned people
     * can already see, scoped to the team once assigned (see
     * AccessControlService::teamScopedUserIds).
     */
    public function store(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.people.view'), 403);

        return redirect()->route('admin.users')->with('status', 'Create accounts in Users. This page no longer creates duplicate accounts.');
    }

    public function show(Request $request, User $user)
    {
        abort_unless($this->access->canManageUser($request->user(), $user) || $request->user()->is($user), 403);

        return view('access-control.people-show', [
            'siteLocations' => Location::orderBy('name')->get(),
            'person' => $user->load(['accessRoles', 'accessPositions', 'extraRoleRecords']),
            'roles' => AccessRole::where('status', 'active')->orderBy('level')->orderBy('name')->get(),
            'combinableRoles' => Role::combinable(),
            'canEditJobRoles' => $request->user()->isAdmin() && ! in_array($user->role, [Role::Admin, Role::Vendor], true),
            'canManageThisUser' => $this->access->canManageUser($request->user(), $user),
            'permissions' => AccessPermission::whereNotIn('key', RolePermissionMatrix::HIDDEN_PERMISSION_KEYS)->orderBy('module')->orderBy('screen')->orderBy('sort_order')->get(),
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
        DB::transaction(function () use ($request, $user, $role, $data) {
            DB::table('access_user_roles')->updateOrInsert(
                ['user_id' => $user->id, 'role_id' => $role->id],
                ['assigned_by' => $request->user()->id, 'starts_at' => now(), 'expires_at' => $data['expires_at'] ?? null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
            );

            // Existing GRN-era accounts default to the legacy permission engine.
            // A newly assigned Access Control role must activate the engine that
            // reads it, otherwise saved module grants appear to have no effect.
            if ($user->access_mode !== 'advanced') {
                $user->forceFill(['access_mode' => 'advanced'])->save();
            }
        });
        $this->access->flushUser($user);
        $this->access->audit($request->user(), 'user.role.assigned', $user, null, ['role_id' => $role->id]);

        return back()->with('status', 'Role assigned.');
    }

    /**
     * Main role + extra roles for the GRN screens (Guard, Store Executive,
     * QC, Store Manager, Finance) - e.g. one person who is Store Manager and
     * also does QC. Super Admin only: these cross every vertical, so a Head
     * must not be able to hand out, say, Finance access.
     */
    public function updateJobRoles(Request $request, User $user)
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only a Super Admin can change job roles.');
        abort_if(in_array($user->role, [Role::Admin, Role::Vendor], true), 403, 'Super Admin and Vendor accounts are managed in Admin > Users.');

        $combinable = array_map(fn (Role $role) => $role->value, Role::combinable());
        $data = $request->validate([
            'main_role' => ['nullable', Rule::in($combinable)],
            'extra_roles' => ['nullable', 'array'],
            'extra_roles.*' => [Rule::in($combinable)],
            'location_id' => ['nullable', 'integer', 'exists:tan90_locations,id'],
        ], [
            'main_role.in' => 'Pick a valid main role.',
        ]);

        $this->saveJobRoles($request->user(), $user, $data['main_role'] ?? null, $data['extra_roles'] ?? []);
        if ($user->location_id != ($data['location_id'] ?? null)) {
            $before = $user->location_id;
            $user->forceFill(['location_id' => $data['location_id'] ?? null])->save();
            $this->access->audit($request->user(), 'user.location.changed', $user, ['location_id' => $before], ['location_id' => $user->location_id]);
        }

        return back()->with('status', 'Job roles and location saved.');
    }

    public function removeRole(Request $request, User $user, AccessRole $role)
    {
        abort_unless($this->access->canManageUser($request->user(), $user) && $this->access->canManageRole($request->user(), $role), 403);
        abort_if($request->user()->is($user) && $role->code === 'ACCESS-SUPER-ADMIN', 422, 'You cannot remove your own Super Admin role.');

        DB::table('access_user_roles')->where('user_id', $user->id)->where('role_id', $role->id)->delete();
        DB::table('access_user_role_assignments')->where('user_id', $user->id)->where('role_id', $role->id)->delete();

        $this->access->flushUser($user);
        $this->access->audit($request->user(), 'user.role.removed', $user, ['role_id' => $role->id], null);

        return back()->with('status', "Role \"{$role->name}\" removed.");
    }

    private function saveJobRoles(User $actor, User $user, ?string $mainRole, array $extraRoles): void
    {
        if (! $mainRole && $extraRoles) {
            throw ValidationException::withMessages(['main_role' => 'Pick a main role before adding extra roles.']);
        }

        $before = ['main_role' => $user->role?->value, 'extra_roles' => array_map(fn (Role $role) => $role->value, $user->extraRoles())];
        $extraRoles = array_values(array_diff(array_unique($extraRoles), [$mainRole]));

        DB::transaction(function () use ($actor, $user, $mainRole, $extraRoles) {
            $user->update(['role' => $mainRole]);
            $user->extraRoleRecords()->whereNotIn('role', $extraRoles)->delete();
            foreach ($extraRoles as $role) {
                UserExtraRole::firstOrCreate(['user_id' => $user->id, 'role' => $role], ['assigned_by' => $actor->id]);
            }
        });

        $user->unsetRelation('extraRoleRecords');
        $this->access->audit($actor, 'user.job_roles.updated', $user, $before, ['main_role' => $mainRole, 'extra_roles' => $extraRoles]);
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
        abort_if(RolePermissionMatrix::isHidden($permission->key), 422, 'This permission is no longer assignable.');
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
