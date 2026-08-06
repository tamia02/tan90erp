<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\Workspace\WorkspaceApproval;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'workspace.approvals.view'), 403);

        $scope = $this->access->teamScopedUserIds($user);
        $status = $request->query('status', 'pending');

        $approvals = WorkspaceApproval::with(['requester', 'approver'])
            ->when($scope, fn ($q) => $q->whereIn('requested_by', $scope)->orWhereIn('approver_id', $scope))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw("field(risk_level,'high','medium','low')")
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('workspace.approvals-index', ['approvals' => $approvals, 'status' => $status, 'canDecide' => $this->access->can($user, 'workspace.approvals.approve')]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'workspace.approvals.view'), 403);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'module' => ['required', 'string', 'max:100'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'risk_level' => ['required', 'in:low,medium,high'],
        ]);

        $approval = WorkspaceApproval::create($data + ['requested_by' => $user->id, 'status' => 'pending']);
        $approval->events()->create(['user_id' => $user->id, 'action' => 'requested']);

        return back()->with('status', 'Approval requested.');
    }

    public function decide(Request $request, WorkspaceApproval $approval)
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'workspace.approvals.approve'), 403);

        $scope = $this->access->teamScopedUserIds($user);
        abort_unless($scope === null || $scope->contains($approval->requested_by), 403);

        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected,returned'],
            'decision_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $approval->update([
            'status' => $data['decision'],
            'decision_notes' => $data['decision_notes'] ?? null,
            'decided_by' => $user->id,
            'decided_at' => now(),
            'approver_id' => $approval->approver_id ?? $user->id,
        ]);
        $approval->events()->create(['user_id' => $user->id, 'action' => $data['decision'], 'detail' => $data['decision_notes'] ?? null]);

        return back()->with('status', 'Decision recorded.');
    }
}
