<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    public const IN_TYPES = ['produced'];

    public const OUT_TYPES = ['consumed', 'wastage', 'dispatched'];

    protected $fillable = ['item_id', 'type', 'qty', 'production_id', 'dispatch_id', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function production(): BelongsTo
    {
        return $this->belongsTo(InventoryProduction::class, 'production_id');
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(InventoryDispatch::class, 'dispatch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
