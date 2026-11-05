<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryProduction extends Model
{
    protected $fillable = ['number', 'fg_item_id', 'qty_produced', 'notes', 'created_by', 'location_id', 'zoho_location_id', 'zoho_status', 'zoho_bundle_id', 'zoho_error'];

    protected function casts(): array
    {
        return ['qty_produced' => 'decimal:3'];
    }

    public function fgItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'fg_item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'production_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
