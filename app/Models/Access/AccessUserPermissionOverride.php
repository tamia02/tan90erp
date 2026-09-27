<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessUserPermissionOverride extends Model
{
    protected $fillable = ['user_id', 'permission_id', 'granted_by', 'allowed', 'effect', 'scope_type', 'scope_json', 'scope_constraints_json', 'field_mode', 'starts_at', 'expires_at', 'reason', 'revoked_at', 'revoked_by', 'status'];

    protected function casts(): array
    {
        return ['allowed' => 'boolean', 'scope_json' => 'array', 'scope_constraints_json' => 'array', 'starts_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(AccessPermission::class, 'permission_id');
    }
}
