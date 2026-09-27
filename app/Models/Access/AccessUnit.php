<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessUnit extends Model
{
    protected $fillable = ['vertical_id', 'code', 'name', 'status'];

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(AccessVertical::class, 'vertical_id');
    }
}
