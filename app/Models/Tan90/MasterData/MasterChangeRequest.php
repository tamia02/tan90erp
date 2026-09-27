<?php

namespace App\Models\Tan90\MasterData;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A governed change request against an already-approved master record.
 * Its own CREATE/APPROVE/REJECT audit events are written explicitly by
 * ApprovalService (not via the generic IsMasterRecord hooks) so the audit
 * trail uses SUBMIT/APPROVE/REJECT rather than generic CREATE/UPDATE.
 */
class MasterChangeRequest extends Model
{
    use SoftDeletes;

    protected $table = 'tan90_master_change_requests';

    protected $fillable = [
        'request_no', 'entity_type', 'entity_id', 'record_code', 'proposed_changes',
        'previous_values', 'reason', 'requested_by', 'owner', 'priority',
        'approval_status', 'reviewed_by', 'reviewed_at', 'review_notes', 'status',
    ];

    protected $casts = [
        'proposed_changes' => 'array',
        'previous_values' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MasterChangeVersion::class, 'tan90_master_change_request_id');
    }
}
