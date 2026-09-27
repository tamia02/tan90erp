<?php

namespace App\Models\Flow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HandlingUnit extends Model
{
    protected $table = 'flow_handling_units';

    protected $fillable = ['customer_order_id', 'shipment_id', 'hu_number', 'weight_kg', 'status', 'sealed_at'];

    protected function casts(): array
    {
        return ['sealed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }
}
