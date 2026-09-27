<?php

namespace App\Models\Flow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Allocation extends Model
{
    protected $table = 'flow_allocations';

    protected $fillable = ['order_line_id', 'inventory_lot_id', 'qty', 'status'];

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    public function inventoryLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function pickTask(): HasOne
    {
        return $this->hasOne(PickTask::class, 'allocation_id');
    }
}
