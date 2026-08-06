<?php

namespace App\Models\Forge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Batch extends Model
{
    protected $table = 'forge_batches';

    protected $fillable = ['work_order_id', 'batch_number', 'qty', 'uom', 'status', 'shelf_life_date', 'released_at'];

    protected function casts(): array
    {
        return [
            'shelf_life_date' => 'date',
            'released_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }
}
