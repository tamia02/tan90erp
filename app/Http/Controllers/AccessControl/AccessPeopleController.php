<?php

namespace App\Http\Controllers\AccessControl;

use App\Http\Controllers\Controller;
use App\Models\Access\AccessPermission;
use App\Models\Access\AccessPosition;
use App\Models\Access\AccessRole;
use App\Models\Access\AccessTeam;
use App\Models\Access\AccessUserPermissionOverride;
use App\Models\Access\AccessVertical;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

    public function create(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.people.view'), 403);

        return view('access-control.people-create', [
            'roles' => AccessRole::where('status', 'active')->orderBy('level')->orderBy('name')->get(),
            'people' => User::where('access_mode', 'advanced')->orderBy('name')->get(),
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
            'role_id' => ['required', 'exists:access_roles,id'],
            'reports_to_user_id' => ['nullable', 'exists:users,id'],
            'report_user_ids' => ['nullable', 'array'],
            'report_user_ids.*' => ['exists:users,id'],
        ]);

        $role = AccessRole::findOrFail($data['role_id']);
        abort_unless($this->access->canManageRole($request->user(), $role), 403, 'Hierarchy rule blocks assigning this role.');

        $person = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'access_mode' => 'advanced',
            'is_active' => true,
        ]);

        DB::table('access_user_roles')->insert([
            'user_id' => $person->id, 'role_id' => $role->id, 'assigned_by' => $request->user()->id,
            'starts_at' => now(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        AccessPosition::create([
            'user_id' => $person->id,
            'hierarchy_level' => $role->hierarchy_level ?? $role->level,
            'vertical_id' => $role->vertical_id,
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
