<?php

namespace App\Models\Workspace;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceExceptionEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['exception_id', 'user_id', 'action', 'detail'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(WorkspaceException::class, 'exception_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
