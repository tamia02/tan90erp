<?php

namespace App\Models\Flow;

use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderLine extends Model
{
    protected $table = 'flow_order_lines';

    protected $fillable = [
        'customer_order_id', 'finished_good_id', 'qty_ordered', 'qty_allocated',
        'qty_picked', 'qty_packed', 'uom',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }

    public function finishedGood(): BelongsTo
    {
        return $this->belongsTo(FinishedGood::class, 'finished_good_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class, 'order_line_id');
    }

    public function outstandingQty(): float
    {
        return (float) $this->qty_ordered - (float) $this->qty_allocated;
    }
}
