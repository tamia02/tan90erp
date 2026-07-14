<?php

namespace App\Models\Tan90\BomRecipeCosting;

use App\Models\Tan90\BomRecipeCosting\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinishedGood extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_finished_goods';

    protected $fillable = [
        'code', 'name', 'category', 'uom', 'pack_size', 'description', 'status', 'approval_status',
    ];

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class, 'tan90_finished_good_id');
    }

    public function boms(): HasMany
    {
        return $this->hasMany(Bom::class, 'tan90_finished_good_id');
    }

    public function routings(): HasMany
    {
        return $this->hasMany(Routing::class, 'tan90_finished_good_id');
    }

    public function costSheets(): HasMany
    {
        return $this->hasMany(CostSheet::class, 'tan90_finished_good_id');
    }
}
