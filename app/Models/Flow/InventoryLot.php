<?php

namespace App\Models\Flow;

use App\Models\Forge\Batch;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLot extends Model
{
    protected $table = 'flow_inventory_lots';

    protected $fillable = [
        'finished_good_id', 'forge_batch_id', 'lot_number', 'warehouse', 'zone', 'bin',
        'qty_received', 'qty_available', 'qty_allocated', 'qty_picked', 'uom',
        'quality_status', 'expiry_date', 'status', 'received_by', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function finishedGood(): BelongsTo
    {
        return $this->belongsTo(FinishedGood::class, 'finished_good_id');
    }

    public function forgeBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'forge_batch_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'inventory_lot_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class, 'inventory_lot_id');
    }

    public function isAtpEligible(): bool
    {
        return $this->quality_status === 'released'
            && $this->status === 'available'
            && $this->qty_available > 0
            && (! $this->expiry_date || $this->expiry_date->isFuture());
    }
}
