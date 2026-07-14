<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Routing extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_routings';

    protected $fillable = [
        'code', 'tan90_finished_good_id', 'name', 'status', 'approval_status',
    ];

    public function finishedGood(): BelongsTo
    {
        return $this->belongsTo(FinishedGood::class, 'tan90_finished_good_id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(RoutingOperation::class, 'tan90_routing_id')->orderBy('sequence');
    }
}
