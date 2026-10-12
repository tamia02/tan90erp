<?php

namespace App\Http\Controllers\AccessControl;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Access\AccessPermission;
use App\Models\Access\AccessPosition;
use App\Models\Access\AccessRole;
use App\Models\Access\AccessTeam;
use App\Models\Access\AccessUserPermissionOverride;
use App\Models\Access\AccessVertical;
use App\Models\User;
use App\Models\UserExtraRole;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

        return view('access-control.people-create', [
            'siteLocations' => \App\Models\Tan90\MasterData\Location::orderBy('name')->get(),
            'roles' => AccessRole::where('status', 'active')->orderBy('level')->orderBy('name')->get(),
            'people' => User::where('access_mode', 'advanced')->orderBy('name')->get(),
            'combinableRoles' => Role::combinable(),
            'canSetJobRoles' => $request->user()->isAdmin(),
        ]);
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

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            // An Access Control role is optional when the person gets a GRN
            // main job role instead (e.g. a Store Manager who also does QC).
            'role_id' => ['nullable', 'required_without:main_role', 'exists:access_roles,id'],
            'main_role' => ['nullable', Rule::in(array_map(fn (Role $role) => $role->value, Role::combinable()))],
            'extra_roles' => ['nullable', 'array'],
            'extra_roles.*' => [Rule::in(array_map(fn (Role $role) => $role->value, Role::combinable()))],
            'reports_to_user_id' => ['nullable', 'exists:users,id'],
            'report_user_ids' => ['nullable', 'array'],
            'report_user_ids.*' => ['exists:users,id'],
            'location_id' => ['nullable', 'integer', 'exists:tan90_locations,id'],
        ], [
            'role_id.required_without' => 'Pick an Access Control role or a main job role.',
        ]);

        abort_if(filled($data['main_role'] ?? null) && ! $request->user()->isAdmin(), 403, 'Only a Super Admin can set job roles.');

        $role = filled($data['role_id'] ?? null) ? AccessRole::findOrFail($data['role_id']) : null;
        abort_unless(! $role || $this->access->canManageRole($request->user(), $role), 403, 'Hierarchy rule blocks assigning this role.');

        $person = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'access_mode' => 'advanced',
            'is_active' => true,
            // Site: Super Admin only, like job roles.
            'location_id' => $request->user()->isAdmin() ? ($data['location_id'] ?? null) : null,
        ]);

        if ($role) {
            DB::table('access_user_roles')->insert([
                'user_id' => $person->id, 'role_id' => $role->id, 'assigned_by' => $request->user()->id,
                'starts_at' => now(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (filled($data['main_role'] ?? null)) {
            $this->saveJobRoles($request->user(), $person, $data['main_role'], $data['extra_roles'] ?? []);
        }

        AccessPosition::create([
            'user_id' => $person->id,
            'hierarchy_level' => $role ? ($role->hierarchy_level ?? $role->level) : AccessRole::LEVEL_EXECUTIVE,
            'vertical_id' => $role?->vertical_id,
            'reports_to_user_id' => $data['reports_to_user_id'] ?? null,
            'is_primary' => true,
            'status' => 'active',
            'starts_at' => now(),
            'created_by' => $request->user()->id,
        ]);

        $reportIds = collect($data['report_user_ids'] ?? []);
        $reportIds->each(function ($reportId) use ($person, $request) {
            AccessPosition::where('user_id', $reportId)->where('is_primary', true)->update(['is_primary' => false]);
            $existing = AccessPosition::where('user_id', $reportId)->latest('id')->first();
            AccessPosition::create([
                'user_id' => $reportId,
                'hierarchy_level' => $existing->hierarchy_level ?? AccessRole::LEVEL_EXECUTIVE,
                'vertical_id' => $existing->vertical_id ?? null,
                'unit_id' => $existing->unit_id ?? null,
                'team_id' => $existing->team_id ?? null,
                'reports_to_user_id' => $person->id,
                'is_primary' => true,
                'status' => 'active',
                'starts_at' => now(),
                'created_by' => $request->user()->id,
            ]);
            $this->access->flushUser(User::find($reportId));
        });

        // Auto-build the combined dashboard: grant this Head every widget
        // permission their assigned team can already see, so /workspace
        // shows the union with no manual dashboard-building step.
        $widgetPermissionKeys = $reportIds
            ->map(fn ($reportId) => User::find($reportId))
            ->filter()
            ->flatMap(fn (User $report) => $this->access->widgetsFor($report)->pluck('permission_key'))
            ->push('workspace.view')
            ->unique()
            ->values();

        foreach ($widgetPermissionKeys as $key) {
            $permission = AccessPermission::where('key', $key)->first();
            if (! $permission) {
                continue;
            }
            AccessUserPermissionOverride::updateOrCreate(
                ['user_id' => $person->id, 'permission_id' => $permission->id],
                [
                    'granted_by' => $request->user()->id, 'allowed' => true, 'effect' => 'allow',
                    'scope_type' => 'all', 'starts_at' => now(), 'status' => 'active',
                    'reason' => 'Auto-granted: combined dashboard for assigned team',
                ]
            );
        }

        $this->access->flushUser($person);
        $this->access->audit($request->user(), 'user.created', $person, null, $data + ['report_user_ids' => $reportIds->all()]);

        return redirect()->route('access.people.show', $person)->with('status', 'User created and team assigned.');
    }

    public function show(Request $request, User $user)
    {
        abort_unless($this->access->canManageUser($request->user(), $user) || $request->user()->is($user), 403);

        return view('access-control.people-show', [
            'siteLocations' => \App\Models\Tan90\MasterData\Location::orderBy('name')->get(),
            'person' => $user->load(['accessRoles', 'accessPositions', 'extraRoleRecords']),
            'roles' => AccessRole::where('status', 'active')->orderBy('level')->orderBy('name')->get(),
            'combinableRoles' => Role::combinable(),
            'canEditJobRoles' => $request->user()->isAdmin() && ! in_array($user->role, [Role::Admin, Role::Vendor], true),
            'canManageThisUser' => $this->access->canManageUser($request->user(), $user),
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
