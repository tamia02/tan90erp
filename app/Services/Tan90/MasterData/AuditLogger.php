<?php

namespace App\Services\Tan90\MasterData;

use App\Models\Tan90\MasterData\MasterAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes immutable rows to tan90_master_audit_logs. Called automatically by
 * the IsMasterRecord model trait for CREATE/UPDATE/ARCHIVE/RESTORE, and
 * explicitly by ApprovalService/controllers for SUBMIT/APPROVE/REJECT/
 * IMPORT/EXPORT/PERMISSION_CHANGE events that aren't plain model writes.
 *
 * Never pass secret values into $summary or $changedFields - settings
 * encryption keeps them out of the database, but the audit log would leak
 * them right back into plain text.
 */
class AuditLogger
{
    public function log(string $event, ?Model $record, string $summary, array $changedFields = [], ?string $moduleOverride = null): MasterAuditLog
    {
        $module = $moduleOverride ?? ($record ? class_basename($record) : 'System');

        return MasterAuditLog::create([
            'event' => $event,
            'module' => $module,
            'entity_type' => $record ? get_class($record) : null,
            'entity_id' => $record?->getKey(),
            'record_label' => $record && method_exists($record, 'auditLabel') ? $record->auditLabel() : null,
            'user_id' => Auth::id(),
            'role_label' => Auth::user()?->tan90Profile?->role?->name,
            'ip_address' => Request::ip(),
            'summary' => $summary,
            'changed_fields' => $changedFields ?: null,
            'occurred_at' => now(),
        ]);
    }

    public function logSystem(string $event, string $module, string $summary): MasterAuditLog
    {
        return $this->log($event, null, $summary, [], $module);
    }
}
