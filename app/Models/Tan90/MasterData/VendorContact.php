<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorContact extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_vendor_contacts';

    protected $fillable = [
        'tan90_vendor_id', 'name', 'designation', 'email', 'phone',
        'is_primary', 'notification_role', 'status', 'approval_status',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'tan90_vendor_id');
    }
}
