<?php

namespace App\Models\Workspace;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceApprovalEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['approval_id', 'user_id', 'action', 'detail'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(WorkspaceApproval::class, 'approval_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
