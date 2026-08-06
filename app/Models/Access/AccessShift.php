<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessShift extends Model
{
    protected $fillable = ['team_id', 'unit_id', 'code', 'name', 'starts_at', 'ends_at', 'status'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(AccessTeam::class, 'team_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(AccessUnit::class, 'unit_id');
    }
}
