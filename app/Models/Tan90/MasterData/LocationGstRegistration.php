<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationGstRegistration extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_location_gst_registrations';

    protected $fillable = [
        'code', 'tan90_location_id', 'gstin', 'legal_name', 'registration_type',
        'effective_from', 'gst_status', 'verified_at', 'status', 'approval_status',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'verified_at' => 'datetime',
    ];

    public static function criticalFields(): array
    {
        return ['gstin', 'gst_status'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'tan90_location_id');
    }
}
