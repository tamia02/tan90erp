<?php

namespace App\Services\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes immutable rows to tan90_audit_logs. Called automatically by the
 * IsMasterRecord/IsConfigRecord model traits for CREATE/UPDATE/ARCHIVE/
 * RESTORE/DELETE, and explicitly by the workflow services below for
 * SUBMIT/APPROVE/REJECT/RELEASE/ROLLUP events that aren't plain model writes
 * (per the Codex prompt: "all create/edit/submit/approve/reject/release/
 * roll-up actions write immutable audit logs").
 */
class AuditTrailService
{
    public function log(string $action, ?Model $record, string $description, array $changes = []): AuditLog
    {
        return AuditLog::create([
            'action' => $action,
            'auditable_type' => $record ? get_class($record) : 'System',
            'auditable_id' => $record?->getKey() ?? 0,
            'user_id' => Auth::id(),
            'description' => $description,
            'changes' => $changes ?: null,
            'created_at' => now(),
        ]);
    }
}
