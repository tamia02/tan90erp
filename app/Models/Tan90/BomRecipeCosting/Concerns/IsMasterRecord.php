<?php

namespace App\Models\Tan90\BomRecipeCosting\Concerns;

use App\Services\Tan90\BomRecipeCosting\AuditTrailService;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Shared behavior for every BOM/Recipe/Costing master model: soft delete,
 * optimistic locking via a `version` column, and an immutable audit trail
 * entry for every create / update / delete / restore. Mirrors the Master
 * Data module's IsMasterRecord trait so both modules behave identically
 * once merged into the host app.
 */
trait IsMasterRecord
{
    use SoftDeletes;

    public static function criticalFields(): array
    {
        return [];
    }

    public static function bootIsMasterRecord(): void
    {
        static::creating(function ($model) {
            if (auth()->check()) {
                $model->created_by = $model->created_by ?? auth()->id();
                $model->updated_by = auth()->id();
            }
        });

        static::updating(function ($model) {
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }
            if ($model->isDirty() && $model->getAttribute('version') !== null) {
                $model->version = (int) $model->version + 1;
            }
        });

        static::created(function ($model) {
            app(AuditTrailService::class)->log('CREATE', $model, "Created {$model->auditLabel()}.");
        });

        static::updated(function ($model) {
            if ($model->wasChanged('deleted_at')) {
                return;
            }
            $changed = array_keys($model->getChanges());
            app(AuditTrailService::class)->log(
                'UPDATE',
                $model,
                'Updated fields: ' . (implode(', ', array_diff($changed, ['updated_at', 'version', 'updated_by'])) ?: 'no value changes') . '.',
                $changed
            );
        });

        static::deleted(function ($model) {
            $event = $model->isForceDeleting() ? 'PURGE' : 'ARCHIVE';
            app(AuditTrailService::class)->log($event, $model, "Soft-archived {$model->auditLabel()}.");
        });

        static::restored(function ($model) {
            app(AuditTrailService::class)->log('RESTORE', $model, "Restored {$model->auditLabel()}.");
        });
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeApprovalStatus($query, string $status)
    {
        return $query->where('approval_status', $status);
    }

    public function auditLabel(): string
    {
        $code = $this->getAttribute('code') ?? '';
        $name = $this->getAttribute('name') ?? '';

        return trim("{$code} {$name}") ?: class_basename($this) . " #{$this->getKey()}";
    }
}
