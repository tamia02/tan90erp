<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

class InventoryBundleDraft extends Model
{
    protected $fillable = ['draft_key', 'created_by', 'location_id', 'payload', 'production_id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
