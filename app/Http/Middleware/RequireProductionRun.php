<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RequireProductionRun
{
    public function handle(Request $request, Closure $next)
    {
        $wo=$request->route('workOrder');
        if(!$wo && $request->route('lot') instanceof \App\Models\Flow\InventoryLot) $wo=$request->route('lot')->forgeBatch?->workOrder;
        $protected=['forge.workorders.material-requests.issue','forge.workorders.mixing','forge.workorders.process','forge.final-qc.store',
            'forge.workorders.bundle.confirm','flow.inventory.putaway','forge.workorders.start','forge.workorders.resume-rework',
            'forge.workorders.record-production','forge.workorders.issue-material','forge.workorders.reserve-material'];
        if($wo instanceof \App\Models\Forge\WorkOrder && (int)$wo->workflow_version>=3 && in_array($request->route()?->getName(),$protected,true)) {
            throw ValidationException::withMessages(['status'=>'Open this order in its production step and use Start / Complete.']);
        }
        return $next($request);
    }
}
