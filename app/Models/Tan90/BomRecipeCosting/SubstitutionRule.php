<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubstitutionRule extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_substitution_rules';

    protected $fillable = [
        'code', 'tan90_recipe_line_id', 'tan90_component_id', 'tan90_substitute_component_id',
        'max_percentage', 'requires_approval', 'status', 'approval_status',
    ];

    protected function casts(): array
    {
        return ['requires_approval' => 'boolean'];
    }

    public function recipeLine(): BelongsTo
    {
        return $this->belongsTo(RecipeLine::class, 'tan90_recipe_line_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'tan90_component_id');
    }

    public function substituteComponent(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'tan90_substitute_component_id');
    }
}
