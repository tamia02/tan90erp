<?php

namespace App\Models\Forge;

use Illuminate\Database\Eloquent\Model;

class ProductionFgMapping extends Model
{
    protected $table = 'production_fg_mappings';

    protected $fillable = ['site_id', 'finished_good_id', 'inventory_fg_item_id', 'rm_warehouse_id', 'fg_warehouse_id', 'reusable_pcm_item_id', 'status', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }
}
