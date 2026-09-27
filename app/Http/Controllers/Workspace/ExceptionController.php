<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\Workspace\WorkspaceException;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;

class ExceptionController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'workspace.exceptions.view'), 403);

        $scope = $this->access->teamScopedUserIds($user);
        $status = $request->query('status', 'open');

        $exceptions = WorkspaceException::with(['raiser', 'assignee'])
            ->when($scope, fn ($q) => $q->whereIn('assigned_to', $scope)->orWhereIn('raised_by', $scope))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw("field(severity,'critical','warning')")
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('workspace.exceptions-index', ['exceptions' => $exceptions, 'status' => $status, 'canAssign' => $this->access->can($user, 'workspace.exceptions.assign')]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'workspace.exceptions.view'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'module' => ['required', 'string', 'max:100'],
            'severity' => ['required', 'in:warning,critical'],
        ]);

        $exception = WorkspaceException::create($data + ['raised_by' => $user->id, 'status' => 'open']);
        $exception->events()->create(['user_id' => $user->id, 'action' => 'raised']);

        return back()->with('status', 'Exception raised.');
    }

    public function acknowledge(Request $request, WorkspaceException $exception)
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'workspace.exceptions.assign'), 403);
        $this->authorizeScope($user, $exception->assigned_to ?? $exception->raised_by);

        $exception->update(['status' => 'acknowledged', 'acknowledged_at' => now(), 'assigned_to' => $exception->assigned_to ?? $user->id]);
        $exception->events()->create(['user_id' => $user->id, 'action' => 'acknowledged']);

        return back()->with('status', 'Exception acknowledged.');
    }

    public function resolve(Request $request, WorkspaceException $exception)
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'workspace.exceptions.assign'), 403);
        $this->authorizeScope($user, $exception->assigned_to ?? $exception->raised_by);

        $data = $request->validate(['resolution_notes' => ['nullable', 'string', 'max:2000']]);

        $exception->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_notes' => $data['resolution_notes'] ?? null]);
        $exception->events()->create(['user_id' => $user->id, 'action' => 'resolved', 'detail' => $data['resolution_notes'] ?? null]);

        return back()->with('status', 'Exception resolved.');
    }

    private function authorizeScope($user, ?int $ownerId): void
    {
        $scope = $this->access->teamScopedUserIds($user);
        abort_unless($scope === null || $ownerId === null || $scope->contains($ownerId), 403);
    }
}
