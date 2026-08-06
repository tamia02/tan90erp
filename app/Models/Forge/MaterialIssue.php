<?php

namespace App\Models\Forge;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialIssue extends Model
{
    protected $table = 'forge_material_issues';

    protected $fillable = [
        'work_order_id', 'item_code', 'item_name', 'lot_number', 'qty', 'uom',
        'movement_type', 'posted_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
