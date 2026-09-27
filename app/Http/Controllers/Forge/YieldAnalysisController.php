<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\WastageRecord;
use App\Models\Forge\WorkOrder;
use App\Services\Access\AccessControlService;
use Illuminate\Http\Request;

class YieldAnalysisController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'forge.yield.view'), 403);

        $workOrders = WorkOrder::with('finishedGood')
            ->where('target_qty', '>', 0)
            ->whereIn('status', ['reconciliation', 'final_qc_pending', 'released_to_fg', 'rework', 'rejected', 'closed'])
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->map(function (WorkOrder $wo) {
                $scrapQty = (float) $wo->rework_qty + (float) $wo->rejected_qty;

                return [
                    'wo' => $wo,
                    'scrap_qty' => $scrapQty,
                    'scrap_pct' => round($scrapQty / (float) $wo->target_qty * 100, 2),
                    'yield_pct' => round((float) $wo->good_qty / (float) $wo->target_qty * 100, 2),
                ];
            });

        $averageScrapPct = $workOrders->isEmpty() ? 0 : round($workOrders->avg('scrap_pct'), 2);
        $worstOffenders = $workOrders->sortByDesc('scrap_pct')->take(5)->values();

        $reasonBreakdown = WastageRecord::query()
            ->selectRaw('reason, count(*) as records, sum(qty) as total_qty')
            ->groupBy('reason')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get();

        return view('forge.yield.index', [
            'workOrders' => $workOrders,
            'averageScrapPct' => $averageScrapPct,
            'worstOffenders' => $worstOffenders,
            'reasonBreakdown' => $reasonBreakdown,
        ]);
    }
}
