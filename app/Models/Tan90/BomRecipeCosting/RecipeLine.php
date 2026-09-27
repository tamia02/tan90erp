<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsConfigRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeLine extends Model
{
    use HasFactory;
    use IsConfigRecord;

    protected $table = 'tan90_recipe_lines';

    protected $fillable = [
        'tan90_recipe_version_id', 'tan90_component_id', 'sequence', 'percentage',
        'quantity', 'uom', 'wastage_percent', 'is_alternate',
    ];

    protected function casts(): array
    {
        return ['is_alternate' => 'boolean'];
    }

    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class, 'tan90_recipe_version_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'tan90_component_id');
    }
}
