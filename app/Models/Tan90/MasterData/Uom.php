<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Uom extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_uoms';

    protected $fillable = [
        'code', 'name', 'type', 'base_uom', 'conversion_factor',
        'decimal_places', 'status', 'approval_status',
    ];

    public static function criticalFields(): array
    {
        return ['conversion_factor', 'base_uom', 'decimal_places'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'tan90_uom_id');
    }
}
