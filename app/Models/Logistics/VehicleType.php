<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;

class VehicleType extends Model
{
    protected $table = 'logistics_vehicle_types';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
