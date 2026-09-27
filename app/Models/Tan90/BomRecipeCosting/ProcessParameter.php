<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessParameter extends Model
{
    use HasFactory;
    use IsConfigRecord;

    protected $table = 'tan90_process_parameters';

    protected $fillable = [
        'tan90_routing_operation_id', 'parameter_name', 'target_value', 'min_value',
        'max_value', 'uom', 'criticality',
    ];

    public function routingOperation(): BelongsTo
    {
        return $this->belongsTo(RoutingOperation::class, 'tan90_routing_operation_id');
    }
}
