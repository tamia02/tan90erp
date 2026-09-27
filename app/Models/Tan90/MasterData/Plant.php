<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plant extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_plants';

    protected $fillable = [
        'code', 'name', 'tan90_business_unit_id', 'tan90_location_id', 'plant_type',
        'manager', 'phone', 'capacity', 'shift_model', 'status', 'approval_status',
    ];

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'tan90_business_unit_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'tan90_location_id');
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class, 'tan90_plant_id');
    }
}
