<?php

namespace App\Models\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessTeam extends Model
{
    protected $fillable = ['vertical_id', 'unit_id', 'manager_user_id', 'code', 'name', 'status'];

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(AccessVertical::class, 'vertical_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(AccessUnit::class, 'unit_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }
}
