<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Recipe extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_recipes';

    protected $fillable = [
        'code', 'tan90_finished_good_id', 'name', 'formula_tolerance_percent', 'status', 'approval_status',
    ];

    public function finishedGood(): BelongsTo
    {
        return $this->belongsTo(FinishedGood::class, 'tan90_finished_good_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class, 'tan90_recipe_id')->orderByDesc('revision_number');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(RecipeVersion::class, 'tan90_recipe_id')->where('is_current', true);
    }
}
