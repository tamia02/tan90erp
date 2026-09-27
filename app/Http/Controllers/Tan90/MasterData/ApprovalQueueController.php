<?php

namespace App\Http\Controllers\Tan90\MasterData;

use App\Http\Controllers\Controller;
use App\Services\Tan90\MasterData\EntityRegistry;
use App\Services\Tan90\MasterData\PermissionService;
use Illuminate\Http\Request;

/**
 * Cross-entity view of every draft/review/pending record - the Laravel
 * equivalent of the demo's collectApprovalQueue(), but built from real
 * per-entity queries (scoped per user) instead of scanning a JS object.
 */
class ApprovalQueueController extends Controller
{
    public function __construct(
        private readonly EntityRegistry $registry,
        private readonly PermissionService $permissions,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', \App\Models\Tan90\MasterData\LegalEntity::class);

        $rows = collect();

        foreach ($this->registry->all() as $slug => $config) {
            if (! empty($config['no_approval'])) {
                continue;
            }

            $query = $config['model']::query()
                ->whereIn('approval_status', ['draft', 'review', 'pending'])
                ->where('status', '!=', 'archived');

            $query = $this->permissions->scopeQuery($query, $request->user(), $config);

            foreach ($query->latest('updated_at')->limit(50)->get() as $record) {
                $rows->push([
                    'slug' => $slug,
                    'id' => $record->id,
                    'module' => $config['title'],
                    'name' => $record->getAttribute($config['primary']) ?? $record->auditLabel(),
                    'code' => $record->getAttribute($config['code']),
                    'status' => $record->approval_status,
                    'updated_at' => $record->updated_at,
                ]);
            }
        }

        $rows = $rows->sortByDesc('updated_at')->values();

        return view('tan90.master-data.approval-queue', ['rows' => $rows]);
    }
}
