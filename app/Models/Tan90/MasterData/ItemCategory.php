<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemCategory extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_item_categories';

    protected $fillable = [
        'code', 'name', 'parent', 'valuation_method', 'qc_required',
        'batch_tracking', 'shelf_life', 'status', 'approval_status',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'tan90_item_category_id');
    }
}
