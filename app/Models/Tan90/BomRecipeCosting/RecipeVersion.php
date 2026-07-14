<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsMasterRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipeVersion extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_recipe_versions';

    protected $fillable = [
        'tan90_recipe_id', 'revision_code', 'revision_number', 'gate_status', 'is_current',
        'effective_from', 'effective_to', 'released_at', 'released_by',
        'tan90_engineering_change_order_id', 'notes', 'status', 'approval_status',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'released_at' => 'datetime',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'tan90_recipe_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecipeLine::class, 'tan90_recipe_version_id')->orderBy('sequence');
    }

    public function engineeringChangeOrder(): BelongsTo
    {
        return $this->belongsTo(EngineeringChangeOrder::class, 'tan90_engineering_change_order_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
