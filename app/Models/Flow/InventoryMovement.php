<?php

namespace App\Models\Flow;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'flow_inventory_movements';

    protected $fillable = [
        'inventory_lot_id', 'movement_type', 'qty', 'warehouse', 'zone', 'bin',
        'reference_type', 'reference_id', 'actor_id', 'reason',
    ];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
