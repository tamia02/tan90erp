<?php

namespace App\Http\Controllers\AccessControl;

use App\Http\Controllers\Controller;
use App\Models\Access\AccessPosition;
use App\Models\Access\AccessRole;
use App\Models\Access\AccessShift;
use App\Models\Access\AccessTeam;
use App\Models\Access\AccessUnit;
use App\Models\Access\AccessVertical;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HierarchyController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.hierarchy.view'), 403);

        return view('access-control.hierarchy', [
            'verticals' => AccessVertical::orderBy('name')->get(),
            'units' => AccessUnit::with('vertical')->orderBy('name')->get(),
            'roles' => AccessRole::with(['vertical', 'users'])->withCount(['users', 'permissions'])->orderBy('level')->get(),
            'teams' => AccessTeam::with(['vertical', 'unit', 'manager'])->get(),
            'shifts' => AccessShift::with(['team', 'unit'])->orderBy('starts_at')->get(),
            'positions' => AccessPosition::with(['user', 'vertical', 'unit', 'team', 'shift', 'manager'])->where('status', 'active')->orderBy('hierarchy_level')->get(),
            'users' => User::where('access_mode', 'advanced')->orderBy('name')->get(),
        ]);
    }

    public function storeVertical(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.hierarchy.manage'), 403);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:access_verticals,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $vertical = AccessVertical::create($data + ['status' => 'active']);
        $this->access->audit($request->user(), 'hierarchy.vertical.created', $vertical, null, $vertical->toArray());

        return back()->with('status', 'Vertical created.');
    }

    public function storeUnit(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.hierarchy.manage'), 403);

        $data = $request->validate([
            'vertical_id' => ['required', 'exists:access_verticals,id'],
            'code' => ['required', 'string', 'max:40', 'unique:access_units,code'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $unit = AccessUnit::create($data + ['status' => 'active']);
        $this->access->audit($request->user(), 'hierarchy.unit.created', $unit, null, $unit->toArray());

        return back()->with('status', 'Unit created.');
    }

    public function storeTeam(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.hierarchy.manage'), 403);

        $data = $request->validate([
            'vertical_id' => ['required', 'exists:access_verticals,id'],
            'unit_id' => ['nullable', 'exists:access_units,id'],
            'manager_user_id' => ['nullable', 'exists:users,id'],
            'code' => ['required', 'string', 'max:40', 'unique:access_teams,code'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $team = AccessTeam::create($data + ['status' => 'active']);
        $this->access->audit($request->user(), 'hierarchy.team.created', $team, null, $team->toArray());

        return back()->with('status', 'Team created.');
    }

    public function storeShift(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.hierarchy.manage'), 403);

        $data = $request->validate([
            'team_id' => ['nullable', 'exists:access_teams,id'],
            'unit_id' => ['nullable', 'exists:access_units,id'],
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
        ]);

        $shift = AccessShift::create($data + ['status' => 'active']);
        $this->access->audit($request->user(), 'hierarchy.shift.created', $shift, null, $shift->toArray());

        return back()->with('status', 'Shift created.');
    }

    public function savePosition(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.hierarchy.manage'), 403);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'hierarchy_level' => ['required', 'integer', Rule::in([1, 2, 3, 4])],
            'vertical_id' => ['nullable', 'exists:access_verticals,id'],
            'unit_id' => ['nullable', 'exists:access_units,id'],
            'team_id' => ['nullable', 'exists:access_teams,id'],
            'shift_id' => ['nullable', 'exists:access_shifts,id'],
            'reports_to_user_id' => ['nullable', 'different:user_id', 'exists:users,id'],
        ]);

        AccessPosition::where('user_id', $data['user_id'])->where('is_primary', true)->update(['is_primary' => false]);
        $position = AccessPosition::updateOrCreate(
            ['user_id' => $data['user_id'], 'is_primary' => true],
            $data + ['status' => 'active', 'starts_at' => now(), 'created_by' => $request->user()->id]
        );
        $this->access->flushUser(User::findOrFail($data['user_id']));
        $this->access->audit($request->user(), 'hierarchy.position.saved', $position, null, $position->toArray());

        return back()->with('status', 'Position saved.');
    }
}
