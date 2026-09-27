<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_locations';

    protected $fillable = [
        'code', 'name', 'type', 'state', 'city', 'pincode', 'gstin', 'address',
        'latitude', 'longitude', 'gst_status', 'status', 'approval_status',
    ];

    public static function criticalFields(): array
    {
        return ['gstin', 'gst_status', 'state'];
    }

    public function plants(): HasMany
    {
        return $this->hasMany(Plant::class, 'tan90_location_id');
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class, 'tan90_location_id');
    }

    public function gstRegistrations(): HasMany
    {
        return $this->hasMany(LocationGstRegistration::class, 'tan90_location_id');
    }
}
