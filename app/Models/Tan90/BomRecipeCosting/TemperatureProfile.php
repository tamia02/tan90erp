<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TemperatureProfile extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_temperature_profiles';

    protected $fillable = [
        'code', 'name', 'min_temp', 'max_temp', 'storage_condition',
        'monitoring_frequency', 'status', 'approval_status',
    ];
}
