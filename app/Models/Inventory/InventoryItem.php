<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    public const TYPES = [
        'rm' => 'Raw Material (RM)',
        'pm' => 'Packaging Material (PM)',
        'fg' => 'Finished Good (FG)',
        'trading' => 'Trading Good',
    ];

    // Only these can be sold / dispatched; RM and PM are consumed in production.
    public const SELLABLE_TYPES = ['fg', 'trading'];

    protected $fillable = ['sku', 'name', 'item_type', 'unit', 'opening_qty', 'active'];

    protected function casts(): array
    {
        return ['opening_qty' => 'decimal:3', 'active' => 'boolean'];
    }

    public function bomLines(): HasMany
    {
        return $this->hasMany(InventoryBomLine::class, 'fg_item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'item_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->item_type] ?? $this->item_type;
    }

    public function displayName(): string
    {
        return $this->name ?: $this->sku;
    }
}
