<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkCenter extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_work_centers';

    protected $fillable = [
        'code', 'name', 'plant', 'capacity_per_hour', 'labor_rate', 'machine_rate',
        'overhead_rate', 'status', 'approval_status',
    ];

    public static function criticalFields(): array
    {
        return ['labor_rate', 'machine_rate', 'overhead_rate'];
    }

    public function routingOperations(): HasMany
    {
        return $this->hasMany(RoutingOperation::class, 'tan90_work_center_id');
    }
}
