<?php

namespace App\Http\Controllers\Tan90\BomRecipeCosting;

use App\Http\Controllers\Controller;
use App\Models\Tan90\BomRecipeCosting\AuditLog;
use Illuminate\Http\Request;

class AuditTrailController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', AuditLog::class);

        $logs = AuditLog::with('user')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('auditable_type'), fn ($q) => $q->where('auditable_type', 'like', '%' . $request->string('auditable_type') . '%'))
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('tan90.brc.audit-logs.index', compact('logs'));
    }
}
