<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapRecovery extends Model
{
    use HasFactory;
    use IsConfigRecord;

    protected $table = 'tan90_scrap_recovery';

    protected $fillable = [
        'tan90_bom_version_id', 'scrap_type', 'quantity', 'uom', 'recovery_percent', 'disposition',
    ];

    public function bomVersion(): BelongsTo
    {
        return $this->belongsTo(BomVersion::class, 'tan90_bom_version_id');
    }
}
