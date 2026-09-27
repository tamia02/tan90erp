<?php

namespace App\Models\Flow;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    protected $table = 'flow_deliveries';

    protected $fillable = [
        'shipment_id', 'customer_order_id', 'receiver_name', 'qty_accepted', 'exception_notes',
        'pod_reference', 'delivered_at', 'closed_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
