<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsMasterRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EngineeringChangeOrder extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_engineering_change_orders';

    protected $fillable = [
        'code', 'object_type', 'object_id', 'reason', 'description', 'status',
        'requested_by', 'approved_by', 'requested_at', 'approved_at', 'approval_status',
    ];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    private const OBJECT_MODELS = [
        'recipe' => RecipeVersion::class,
        'bom' => BomVersion::class,
        'routing' => Routing::class,
        'cost_standard' => CostSheet::class,
    ];

    /** Resolves the concrete revisioned record this ECO was raised against. */
    public function object(): ?Model
    {
        $modelClass = self::OBJECT_MODELS[$this->object_type] ?? null;

        return $modelClass ? $modelClass::find($this->object_id) : null;
    }

    public function changeImpacts(): HasMany
    {
        return $this->hasMany(ChangeImpact::class, 'tan90_engineering_change_order_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
