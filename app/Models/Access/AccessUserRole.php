<?php

namespace App\Models\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessUserRole extends Model
{
    protected $fillable = ['user_id', 'role_id', 'assigned_by', 'starts_at', 'expires_at', 'status'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(AccessRole::class, 'role_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
