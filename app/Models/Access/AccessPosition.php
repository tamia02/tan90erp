<?php

namespace App\Models\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessPosition extends Model
{
    protected $fillable = ['user_id', 'hierarchy_level', 'vertical_id', 'unit_id', 'team_id', 'shift_id', 'reports_to_user_id', 'is_primary', 'starts_at', 'ends_at', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reports_to_user_id');
    }

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(AccessVertical::class, 'vertical_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(AccessUnit::class, 'unit_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(AccessTeam::class, 'team_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(AccessShift::class, 'shift_id');
    }
}
