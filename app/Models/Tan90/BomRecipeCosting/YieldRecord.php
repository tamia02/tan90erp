<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YieldRecord extends Model
{
    use HasFactory;
    use IsConfigRecord;

    protected $table = 'tan90_yield_records';

    protected $fillable = [
        'tan90_bom_version_id', 'batch_size', 'expected_yield', 'actual_yield',
        'yield_percent', 'loss_percent', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    public function bomVersion(): BelongsTo
    {
        return $this->belongsTo(BomVersion::class, 'tan90_bom_version_id');
    }
}
