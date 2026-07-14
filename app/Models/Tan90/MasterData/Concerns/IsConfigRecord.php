<?php

namespace App\Models\Tan90\MasterData\Concerns;

use App\Services\Tan90\MasterData\AuditLogger;

/**
 * Lighter sibling of IsMasterRecord for pure reference/config tables that
 * intentionally have no `deleted_at`, `version`, or `approval_status` column
 * (number series, SLA policies, document rules, notification templates,
 * integration connections, data quality rules, approval workflow steps).
 *
 * IsMasterRecord always pulls in SoftDeletes, which adds a global
 * `whereNull('deleted_at')` scope to every query - applying it to a table
 * without that column breaks every query with an "unknown column" error.
 * These tables are hard-deleted instead; still fully audited.
 */
trait IsConfigRecord
{
    public static function bootIsConfigRecord(): void
    {
        static::created(function ($model) {
            app(AuditLogger::class)->log('CREATE', $model, "Created {$model->auditLabel()}.");
        });

        static::updated(function ($model) {
            $changed = array_keys($model->getChanges());
            app(AuditLogger::class)->log(
                'UPDATE',
                $model,
                'Updated fields: ' . (implode(', ', array_diff($changed, ['updated_at'])) ?: 'no value changes') . '.',
                $changed
            );
        });

        static::deleted(function ($model) {
            app(AuditLogger::class)->log('DELETE', $model, "Deleted {$model->auditLabel()}.");
        });
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function auditLabel(): string
    {
        $code = $this->getAttribute('code') ?? '';
        $name = $this->getAttribute('name') ?? '';

        return trim("{$code} {$name}") ?: class_basename($this) . " #{$this->getKey()}";
    }
}
