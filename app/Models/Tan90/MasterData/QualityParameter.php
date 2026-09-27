<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityParameter extends Model
{
    use IsMasterRecord;

    protected $table = 'tan90_quality_parameters';

    protected $fillable = [
        'code', 'name', 'tan90_item_category_id', 'data_type', 'unit',
        'min_value', 'max_value', 'sampling', 'criticality', 'status', 'approval_status',
    ];

    public function itemCategory(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'tan90_item_category_id');
    }
}
