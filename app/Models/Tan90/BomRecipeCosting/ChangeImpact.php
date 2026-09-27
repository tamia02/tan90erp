<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChangeImpact extends Model
{
    use HasFactory;
    use IsConfigRecord;

    protected $table = 'tan90_change_impacts';

    protected $fillable = [
        'tan90_engineering_change_order_id', 'impacted_object_type', 'impacted_object_id', 'impact_description',
    ];

    public function engineeringChangeOrder(): BelongsTo
    {
        return $this->belongsTo(EngineeringChangeOrder::class, 'tan90_engineering_change_order_id');
    }
}
