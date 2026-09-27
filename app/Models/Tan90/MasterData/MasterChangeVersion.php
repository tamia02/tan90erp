<?php

namespace App\Models\Tan90\MasterData;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable, effective-dated snapshot of a master record. Written once by
 * ApprovalService::approveChangeRequest(); never updated or deleted from the UI.
 */
class MasterChangeVersion extends Model
{
    protected $table = 'tan90_master_change_versions';

    public $timestamps = true;

    protected $fillable = [
        'tan90_master_change_request_id', 'entity_type', 'entity_id',
        'version_number', 'snapshot', 'created_by', 'effective_from',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'effective_from' => 'datetime',
    ];

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(MasterChangeRequest::class, 'tan90_master_change_request_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
