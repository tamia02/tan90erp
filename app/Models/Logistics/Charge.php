<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;

class Charge extends Model
{
    protected $table = 'logistics_charges';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['voided_at' => 'datetime', 'amount' => 'decimal:2'];
    }
}
