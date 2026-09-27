<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlternateComponent extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_alternate_components';

    protected $fillable = [
        'tan90_component_id', 'tan90_alternate_component_id', 'ratio',
        'effective_from', 'effective_to', 'status', 'approval_status',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'tan90_component_id');
    }

    public function alternateComponent(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'tan90_alternate_component_id');
    }
}
