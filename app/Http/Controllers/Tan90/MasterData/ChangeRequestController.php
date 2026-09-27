<?php

namespace App\Http\Controllers\Tan90\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Tan90\MasterData\MasterChangeRequest;
use App\Services\Tan90\MasterData\ApprovalService;
use App\Services\Tan90\MasterData\EntityRegistry;
use App\Services\Tan90\MasterData\PermissionService;
use Illuminate\Http\Request;

/**
 * Reviews change requests opened by MasterDataController::update() whenever
 * a critical field is edited on an already-approved record (see
 * ApprovalService::requiresChangeRequest/requestCriticalChange).
 */
class ChangeRequestController extends Controller
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly EntityRegistry $registry,
        private readonly PermissionService $permissions,
    ) {
    }

    public function index(Request $request)
    {
        abort_unless($this->permissions->can($request->user(), 'view'), 403);

        $query = MasterChangeRequest::query()->with(['requester', 'reviewer'])->latest();

        if ($request->filled('status')) {
            $query->where('approval_status', $request->string('status'));
        }

        return view('tan90.master-data.change-requests', [
            'requests' => $query->paginate(20)->withQueryString(),
        ]);
    }

    public function show(Request $request, MasterChangeRequest $changeRequest)
    {
        abort_unless($this->permissions->can($request->user(), 'view'), 403);

        $entity = $this->registry->get($changeRequest->entity_type);

        return view('tan90.master-data.change-request-show', [
            'changeRequest' => $changeRequest->load(['requester', 'reviewer', 'versions']),
            'entity' => $entity,
        ]);
    }

    public function approve(Request $request, MasterChangeRequest $changeRequest)
    {
        abort_unless($this->permissions->can($request->user(), 'approve'), 403);
        abort_if($changeRequest->approval_status !== 'pending' && $changeRequest->approval_status !== 'review', 422, 'Only pending change requests can be approved.');

        $entity = $this->registry->get($changeRequest->entity_type);
        $this->approvals->approveChangeRequest($entity, $changeRequest, $request->user(), $request->input('notes'));

        return back()->with('status', "Change request {$changeRequest->request_no} approved and applied.");
    }

    public function reject(Request $request, MasterChangeRequest $changeRequest)
    {
        abort_unless($this->permissions->can($request->user(), 'approve'), 403);

        $this->approvals->rejectChangeRequest($changeRequest, $request->user(), $request->input('notes'));

        return back()->with('status', "Change request {$changeRequest->request_no} rejected.");
    }
}
