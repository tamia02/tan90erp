<?php

namespace App\Http\Controllers\Tan90\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Tan90\MasterData\MasterAuditLog;
use App\Services\Tan90\MasterData\PermissionService;
use Illuminate\Http\Request;

/**
 * Read-only. No store/update/destroy actions exist for this controller by
 * design - MasterAuditLog also blocks update()/delete() at the model layer,
 * so the audit trail cannot be altered even by a future code change that
 * accidentally wires up a write route.
 */
class AuditTrailController extends Controller
{
    public function __construct(private readonly PermissionService $permissions)
    {
    }

    public function index(Request $request)
    {
        abort_unless($this->permissions->can($request->user(), 'view'), 403);

        $query = MasterAuditLog::query()->with('user')->latest('occurred_at');

        if ($request->filled('event')) {
            $query->where('event', $request->string('event'));
        }
        if ($request->filled('module')) {
            $query->where('module', 'like', '%' . $request->string('module') . '%');
        }
        if ($request->filled('q')) {
            $term = '%' . $request->string('q') . '%';
            $query->where(fn ($q) => $q->orWhere('record_label', 'like', $term)->orWhere('summary', 'like', $term));
        }

        return view('tan90.master-data.audit-logs', [
            'logs' => $query->paginate(25)->withQueryString(),
            'events' => MasterAuditLog::query()->distinct()->orderBy('event')->pluck('event'),
        ]);
    }
}
