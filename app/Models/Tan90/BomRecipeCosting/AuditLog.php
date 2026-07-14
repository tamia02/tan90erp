<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The audit sink itself — deliberately has neither IsMasterRecord nor
 * IsConfigRecord (both traits write to this table on every model event;
 * applying either here would recurse).
 */
class AuditLog extends Model
{
    protected $table = 'tan90_audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'action', 'auditable_type', 'auditable_id', 'user_id', 'description', 'changes', 'created_at',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
