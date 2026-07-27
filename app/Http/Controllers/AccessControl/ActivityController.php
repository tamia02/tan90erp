<?php

namespace App\Http\Controllers\AccessControl;

use App\Http\Controllers\Controller;
use App\Models\Access\AccessAuditLog;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.activity.view'), 403);
        $query = AccessAuditLog::latest('created_at');
        if ($request->filled('action')) {
            $query->where('action', 'like', '%'.$request->string('action').'%');
        }

        return view('access-control.activity', ['logs' => $query->paginate(20)->withQueryString()]);
    }
}
