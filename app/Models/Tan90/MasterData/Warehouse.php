<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Warehouse extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_warehouses';

    protected $fillable = [
        'code', 'name', 'tan90_plant_id', 'tan90_location_id', 'warehouse_type',
        'manager', 'capacity', 'temperature_zone', 'bin_tracking', 'status', 'approval_status',
    ];

    public static function criticalFields(): array
    {
        return ['warehouse_type', 'bin_tracking'];
    }

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'tan90_plant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'tan90_location_id');
    }
}
