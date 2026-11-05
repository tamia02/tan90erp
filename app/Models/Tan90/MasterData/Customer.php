<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_customers';

    protected $fillable = [
        'code', 'name', 'gstin', 'segment', 'state', 'city',
        'credit_limit', 'payment_terms', 'sales_owner', 'status', 'approval_status',
    ];

    protected $casts = ['zoho_address_checked_at' => 'datetime'];

    public static function criticalFields(): array
    {
        return ['gstin', 'credit_limit'];
    }

    // Pickers (PO delivery, Outward Shipment) list only customers with an address.
    public function scopeWithAddress($query)
    {
        return $query->whereNotNull('city')->where('city', '!=', '');
    }
}
