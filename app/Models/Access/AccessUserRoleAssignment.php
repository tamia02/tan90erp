<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessUserRoleAssignment extends Model
{
    protected $fillable = ['user_id', 'role_id', 'vertical_id', 'unit_id', 'team_id', 'starts_at', 'expires_at', 'status', 'is_primary', 'assigned_by', 'reason'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'expires_at' => 'datetime', 'is_primary' => 'boolean'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(AccessRole::class, 'role_id');
    }
}
