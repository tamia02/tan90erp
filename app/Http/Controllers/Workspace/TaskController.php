<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\Workspace\WorkspaceTask;
use App\Services\Access\AccessControlService;
use App\Support\SiteLocation;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'workspace.tasks.view'), 403);

        $scope = $this->access->teamScopedUserIds($user);
        $status = $request->query('status', 'open');
        $permissions = WorkspaceTask::whereNotNull('target_permission')->distinct()->pluck('target_permission')
            ->filter(fn ($key) => $this->access->can($user, $key))->values()->all();
        $site = SiteLocation::forUser($user);

        $tasks = WorkspaceTask::with(['assignee', 'creator'])
            ->when($site !== null, fn ($q) => $q->where(fn ($s) => $s->whereNull('site_id')->orWhere('site_id', $site)))
            ->where(function ($q) use ($scope, $permissions) {
                $q->where(function ($legacy) use ($scope) {
                    $legacy->whereNull('event_key');
                    if ($scope) $legacy->where(fn ($s) => $s->whereIn('assigned_to', $scope)->orWhereIn('created_by', $scope));
                })->orWhere(fn ($production) => $production->whereNotNull('event_key')->whereIn('target_permission', $permissions));
            })
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw("field(priority,'critical','high','medium','low')")
            ->orderBy('due_at')
            ->paginate(20)
            ->withQueryString();

        return view('workspace.tasks-index', ['tasks' => $tasks, 'status' => $status, 'canComplete' => $this->access->can($user, 'workspace.tasks.complete')]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'workspace.tasks.complete'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'module' => ['required', 'string', 'max:100'],
            'priority' => ['required', 'in:low,medium,high,critical'],
            'due_at' => ['nullable', 'date'],
        ]);

        $task = WorkspaceTask::create($data + ['created_by' => $user->id, 'status' => 'open']);
        $task->events()->create(['user_id' => $user->id, 'action' => 'created', 'detail' => null]);

        return back()->with('status', 'Task created.');
    }

    public function claim(Request $request, WorkspaceTask $task)
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'workspace.tasks.complete'), 403);
        abort_if($task->event_key !== null, 422, 'Production tasks close when the linked ERP action is completed.');
        $this->authorizeScope($user, $task->assigned_to ?? $task->created_by);

        $task->update(['assigned_to' => $user->id, 'status' => 'in_progress']);
        $task->events()->create(['user_id' => $user->id, 'action' => 'claimed']);

        return back()->with('status', 'Task claimed.');
    }

    public function complete(Request $request, WorkspaceTask $task)
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'workspace.tasks.complete'), 403);
        abort_if($task->event_key !== null, 422, 'Production tasks close when the linked ERP action is completed.');
        $this->authorizeScope($user, $task->assigned_to ?? $task->created_by);

        $task->update(['status' => 'completed', 'completed_at' => now()]);
        $task->events()->create(['user_id' => $user->id, 'action' => 'completed']);

        return back()->with('status', 'Task completed.');
    }

    private function authorizeScope($user, ?int $ownerId): void
    {
        $scope = $this->access->teamScopedUserIds($user);
        abort_unless($scope === null || $ownerId === null || $scope->contains($ownerId), 403);
    }
}
