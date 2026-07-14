<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualitySpec extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_quality_specs';

    protected $fillable = [
        'code', 'tan90_finished_good_id', 'tan90_component_id', 'parameter_name',
        'min_value', 'max_value', 'uom', 'criticality', 'status', 'approval_status',
    ];

    public function finishedGood(): BelongsTo
    {
        return $this->belongsTo(FinishedGood::class, 'tan90_finished_good_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'tan90_component_id');
    }
}
