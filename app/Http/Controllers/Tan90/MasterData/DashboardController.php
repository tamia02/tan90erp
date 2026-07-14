<?php

namespace App\Http\Controllers\Tan90\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Tan90\MasterData\Item;
use App\Models\Tan90\MasterData\MasterAuditLog;
use App\Models\Tan90\MasterData\MasterChangeRequest;
use App\Models\Tan90\MasterData\Vendor;
use App\Services\Tan90\MasterData\EntityRegistry;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly EntityRegistry $registry)
    {
    }

    public function index(Request $request)
    {
        $pending = MasterChangeRequest::whereIn('approval_status', ['pending', 'review'])->count();

        foreach ($this->registry->all() as $config) {
            if (empty($config['no_approval'])) {
                $pending += $config['model']::whereIn('approval_status', ['draft', 'review', 'pending'])->count();
            }
        }

        return view('tan90.master-data.dashboard', [
            'kpis' => [
                'active_items' => Item::active()->count(),
                'pending_approvals' => $pending,
                'active_vendors' => Vendor::active()->count(),
            ],
            'recentAudit' => MasterAuditLog::latest('occurred_at')->limit(6)->get(),
            'navGroups' => $this->registry->navGroups(),
        ]);
    }
}
