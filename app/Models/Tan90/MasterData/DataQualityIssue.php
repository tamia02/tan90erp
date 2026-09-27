<?php

namespace App\Models\Tan90\MasterData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Written by DataQualityScanner (create/update-if-exists, keyed by
 * rule_code + record_label - see the unique index) and closed by
 * DataQualityController::resolve(). Not part of the generic entity
 * registry: it has its own controller/view, like MasterAuditLog.
 */
class DataQualityIssue extends Model
{
    protected $table = 'tan90_data_quality_issues';

    protected $fillable = [
        'tan90_data_quality_rule_id', 'rule_code', 'entity', 'record_label',
        'entity_type', 'entity_id', 'issue', 'severity', 'owner',
        'detected_at', 'suggested_action', 'resolution_status',
    ];

    protected $casts = ['detected_at' => 'datetime'];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DataQualityRule::class, 'tan90_data_quality_rule_id');
    }
}
