<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;

class TemperatureClass extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_temperature_classes';

    protected $fillable = [
        'code', 'name', 'min_temp', 'max_temp', 'excursion',
        'monitoring', 'alarm_required', 'status', 'approval_status',
    ];
}
