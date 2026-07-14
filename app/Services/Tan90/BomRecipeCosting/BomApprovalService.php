<?php

namespace App\Services\Tan90\BomRecipeCosting;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-step maker-checker for the module's simple reference masters
 * (Finished Goods, Components, Work Centers, ...) — the more elaborate
 * multi-step release workflow for Recipes/BOMs/Routings/Cost Sheets lives
 * in ReleaseGateService instead, since those follow the fixed P0 gate order
 * rather than a plain submit/approve/reject cycle.
 */
class BomApprovalService
{
    public function __construct(private AuditTrailService $auditTrailService)
    {
    }

    public function submit(Model $record): Model
    {
        $record->update(['approval_status' => 'review']);
        $this->auditTrailService->log('SUBMIT', $record, "Submitted {$record->auditLabel()} for approval.");

        return $record;
    }

    public function approve(Model $record): Model
    {
        $record->update(['approval_status' => 'approved']);
        $this->auditTrailService->log('APPROVE', $record, "Approved {$record->auditLabel()}.");

        return $record;
    }

    public function reject(Model $record, ?string $reason = null): Model
    {
        $record->update(['approval_status' => 'rejected']);
        $this->auditTrailService->log('REJECT', $record, "Rejected {$record->auditLabel()}." . ($reason ? " Reason: {$reason}" : ''));

        return $record;
    }
}
