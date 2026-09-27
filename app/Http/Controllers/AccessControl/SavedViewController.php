<?php

namespace App\Http\Controllers\AccessControl;

use App\Http\Controllers\Controller;
use App\Models\Access\AccessPosition;
use App\Models\Access\AccessRole;
use App\Models\Access\AccessSavedView;
use App\Models\Access\AccessTeam;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SavedViewController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'views.use_assigned') || $this->access->can($request->user(), 'views.manage_user'), 403);

        $views = AccessSavedView::with('owner')->orderBy('module')->orderBy('name')->paginate(12);
        $assignments = DB::table('access_saved_view_assignments')
            ->whereIn('saved_view_id', $views->pluck('id'))
            ->get()
            ->groupBy('saved_view_id');

        return view('access-control.views-index', [
            'views' => $views,
            'assignments' => $assignments,
            'roles' => AccessRole::orderBy('name')->get(),
            'teams' => AccessTeam::orderBy('name')->get(),
            'people' => User::where('access_mode', 'advanced')->orderBy('name')->get(),
            'canManage' => $this->access->can($request->user(), 'views.manage_role') || $this->access->can($request->user(), 'views.manage_team') || $this->access->can($request->user(), 'views.manage_user') || $this->access->can($request->user(), 'views.create_personal'),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'views.create_personal') || $this->access->can($request->user(), 'views.manage_role') || $this->access->can($request->user(), 'views.manage_team') || $this->access->can($request->user(), 'views.manage_user'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'module' => ['required', 'string', 'max:255'],
            'screen_key' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'columns' => ['nullable', 'string', 'max:2000'],
            'filters' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['draft', 'published'])],
        ]);

        $view = AccessSavedView::create([
            'uuid' => (string) Str::uuid(),
            'key' => Str::slug($data['module'].'-'.$data['name']).'-'.Str::random(6),
            'module' => $data['module'],
            'screen_key' => $data['screen_key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'owner_user_id' => $request->user()->id,
            'owner_level' => AccessPosition::where('user_id', $request->user()->id)->where('is_primary', true)->value('hierarchy_level') ?? 4,
            'columns_json' => $data['columns'] ? array_map('trim', explode(',', $data['columns'])) : null,
            'filters_json' => $data['filters'] ? array_map('trim', explode(',', $data['filters'])) : null,
            'status' => $data['status'],
        ]);

        $this->access->audit($request->user(), 'saved_view.created', $view, null, $view->toArray());

        return redirect()->route('access.views.index')->with('status', 'Saved view created.');
    }

    public function assign(Request $request, AccessSavedView $view)
    {
        abort_unless($this->access->can($request->user(), 'views.manage_role') || $this->access->can($request->user(), 'views.manage_team') || $this->access->can($request->user(), 'views.manage_user'), 403);

        $data = $request->validate([
            'assignable_type' => ['required', Rule::in(['role', 'team', 'user'])],
            'assignable_id' => ['required', 'integer'],
            'is_default' => ['boolean'],
            'can_personalise' => ['boolean'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $exists = match ($data['assignable_type']) {
            'role' => AccessRole::whereKey($data['assignable_id'])->exists(),
            'team' => AccessTeam::whereKey($data['assignable_id'])->exists(),
            'user' => User::whereKey($data['assignable_id'])->exists(),
        };
        abort_unless($exists, 422, 'Assignment target is invalid.');

        DB::table('access_saved_view_assignments')->updateOrInsert(
            ['saved_view_id' => $view->id, 'assignable_type' => $data['assignable_type'], 'assignable_id' => $data['assignable_id']],
            [
                'is_default' => (bool) ($data['is_default'] ?? false),
                'can_personalise' => (bool) ($data['can_personalise'] ?? false),
                'expires_at' => $data['expires_at'] ?? null,
                'assigned_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]
        );

        $this->access->audit($request->user(), 'saved_view.assigned', $view, null, $data);

        return back()->with('status', 'Saved view assigned.');
    }

    public function publish(Request $request, AccessSavedView $view)
    {
        abort_unless($this->access->can($request->user(), 'views.manage_role') || $this->access->can($request->user(), 'views.manage_team') || $this->access->can($request->user(), 'views.manage_user'), 403);

        $before = $view->toArray();
        $view->update(['status' => $view->status === 'published' ? 'draft' : 'published', 'version' => $view->version + 1]);
        $this->access->audit($request->user(), 'saved_view.status_changed', $view, $before, $view->fresh()->toArray());

        return back()->with('status', 'Saved view '.($view->status === 'published' ? 'published' : 'moved back to draft').'.');
    }
}
