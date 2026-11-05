<?php

namespace App\Services\Forge;

use App\Models\Forge\WorkOrder;
use App\Support\SiteLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class WorkOrderListing
{
    public const PHASES = [
        'materials' => ['label' => 'RM & PM Issue', 'statuses' => ['released', 'material_reserved']],
        'mixing' => ['label' => 'Manual + Mixing', 'statuses' => ['material_issued', 'in_progress']],
        'filling-sealing' => ['label' => 'Filling & Sealing', 'statuses' => ['in_progress', 'reconciliation']],
        'consumption-fg' => ['label' => 'Consumption & FG Records', 'statuses' => ['final_qc_pending', 'bundle_pending', 'released_to_fg', 'closed']],
    ];

    public function query(Request $request): Builder
    {
        $user = $request->user();
        $site = SiteLocation::forUser($user);
        $phase = $request->string('phase')->toString();
        $status = $request->string('status')->toString();
        $search = trim($request->string('search')->toString());
        $allData = $request->query('scope') === 'all';
        if ($allData) { $phase = ''; $status = ''; $search = ''; }

        return WorkOrder::query()
            ->with('finishedGood')
            ->when($site !== null, fn (Builder $query) => $query->where('site_id', $site))
            ->when(! $allData && $request->query('view') === 'active', fn (Builder $query) => $query->whereNotIn('status', ['closed', 'rejected']))
            ->when(! $allData && $request->query('view') === 'history', fn (Builder $query) => $query->whereIn('status', ['closed', 'rejected']))
            ->when(isset(self::PHASES[$phase]), fn (Builder $query) => $query->whereIn('status', self::PHASES[$phase]['statuses']))
            ->when(in_array($status, WorkOrder::STATUSES, true), fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('wo_number', 'like', '%'.$search.'%')
                        ->orWhereHas('finishedGood', fn (Builder $finishedGood) => $finishedGood
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('code', 'like', '%'.$search.'%'));
                });
            });
    }

    public function activePhase(Request $request): ?array
    {
        $phase = $request->string('phase')->toString();

        return self::PHASES[$phase] ?? null;
    }
}
