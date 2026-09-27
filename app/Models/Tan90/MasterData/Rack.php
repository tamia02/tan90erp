<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rack extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_racks';

    protected $fillable = ['code', 'name', 'tan90_warehouse_zone_id', 'capacity', 'status', 'approval_status'];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(WarehouseZone::class, 'tan90_warehouse_zone_id');
    }

    public function shelves(): HasMany
    {
        return $this->hasMany(Shelf::class, 'tan90_rack_id');
    }
}
