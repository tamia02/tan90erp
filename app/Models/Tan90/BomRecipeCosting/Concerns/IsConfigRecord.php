<?php

namespace App\Models\Tan90\BomRecipeCosting\Concerns;

use App\Services\Tan90\BomRecipeCosting\AuditTrailService;

/**
 * Lighter sibling of IsMasterRecord for child/derived rows that intentionally
 * have no `deleted_at`/`version`/`approval_status` column: line items,
 * revision-detail rows, cost roll-up/simulation/variance results, release
 * gates, approvals, sync jobs. Still fully audited, just hard-deleted and
 * without an independent approval workflow of its own (it inherits its
 * parent master record's approval state).
 */
trait IsConfigRecord
{
    public static function bootIsConfigRecord(): void
    {
        static::created(function ($model) {
            app(AuditTrailService::class)->log('CREATE', $model, "Created {$model->auditLabel()}.");
        });

        static::updated(function ($model) {
            $changed = array_keys($model->getChanges());
            app(AuditTrailService::class)->log(
                'UPDATE',
                $model,
                'Updated fields: ' . (implode(', ', array_diff($changed, ['updated_at'])) ?: 'no value changes') . '.',
                $changed
            );
        });

        static::deleted(function ($model) {
            app(AuditTrailService::class)->log('DELETE', $model, "Deleted {$model->auditLabel()}.");
        });
    }

    public function auditLabel(): string
    {
        $code = $this->getAttribute('code') ?? '';

        return trim((string) $code) ?: class_basename($this) . " #{$this->getKey()}";
    }
}
