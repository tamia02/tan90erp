<?php

namespace App\Models\Logistics;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $table = 'logistics_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
