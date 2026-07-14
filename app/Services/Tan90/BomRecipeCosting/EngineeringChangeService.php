<?php

namespace App\Services\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\EngineeringChangeOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class EngineeringChangeService
{
    public function __construct(private AuditTrailService $auditTrailService)
    {
    }

    public function raise(string $objectType, int $objectId, string $reason, ?string $description = null): EngineeringChangeOrder
    {
        $eco = EngineeringChangeOrder::create([
            'code' => 'ECO-' . strtoupper(Str::ulid()->toBase32() ?: uniqid()),
            'object_type' => $objectType,
            'object_id' => $objectId,
            'reason' => $reason,
            'description' => $description,
            'status' => 'draft',
            'requested_by' => Auth::id(),
            'requested_at' => now(),
        ]);

        $this->auditTrailService->log('ECO_RAISE', $eco, "Raised {$eco->code} for {$objectType} #{$objectId}: {$reason}");

        return $eco;
    }

    public function recordImpact(EngineeringChangeOrder $eco, string $impactedObjectType, int $impactedObjectId, ?string $impactDescription = null): void
    {
        $eco->changeImpacts()->create([
            'impacted_object_type' => $impactedObjectType,
            'impacted_object_id' => $impactedObjectId,
            'impact_description' => $impactDescription,
        ]);
    }

    public function approve(EngineeringChangeOrder $eco): EngineeringChangeOrder
    {
        $eco->update(['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now(), 'approval_status' => 'approved']);
        $this->auditTrailService->log('ECO_APPROVE', $eco, "Approved {$eco->code}.");

        return $eco;
    }

    public function implement(EngineeringChangeOrder $eco): EngineeringChangeOrder
    {
        $eco->update(['status' => 'implemented']);
        $this->auditTrailService->log('ECO_IMPLEMENT', $eco, "Implemented {$eco->code}.");

        return $eco;
    }
}
