<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_vendors';

    protected $fillable = [
        'code', 'name', 'gstin', 'gst_status', 'category', 'state', 'city',
        'contact', 'phone', 'email', 'payment_terms', 'rating', 'portal_enabled',
        'status', 'approval_status',
    ];

    public static function criticalFields(): array
    {
        return ['gstin', 'gst_status', 'payment_terms'];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(VendorContact::class, 'tan90_vendor_id');
    }
}
