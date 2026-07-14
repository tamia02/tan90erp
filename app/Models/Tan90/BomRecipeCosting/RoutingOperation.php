<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoutingOperation extends Model
{
    use HasFactory;
    use IsConfigRecord;

    protected $table = 'tan90_routing_operations';

    protected $fillable = [
        'tan90_routing_id', 'sequence', 'operation_name', 'tan90_work_center_id',
        'setup_time_minutes', 'run_time_minutes',
    ];

    public function routing(): BelongsTo
    {
        return $this->belongsTo(Routing::class, 'tan90_routing_id');
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class, 'tan90_work_center_id');
    }

    public function processParameters(): HasMany
    {
        return $this->hasMany(ProcessParameter::class, 'tan90_routing_operation_id');
    }
}
