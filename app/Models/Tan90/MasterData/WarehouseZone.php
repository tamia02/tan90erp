<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseZone extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_warehouse_zones';

    protected $fillable = [
        'code', 'name', 'tan90_warehouse_id', 'zone_type',
        'allowed_material', 'hazard_class', 'status', 'approval_status',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'tan90_warehouse_id');
    }

    public function racks(): HasMany
    {
        return $this->hasMany(Rack::class, 'tan90_warehouse_zone_id');
    }
}
