<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegalEntity extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_legal_entities';

    protected $fillable = [
        'code', 'name', 'cin', 'pan', 'gstin', 'country', 'state',
        'base_currency', 'timezone', 'fiscal_year', 'status', 'approval_status',
    ];

    public static function criticalFields(): array
    {
        return ['gstin', 'pan', 'cin', 'base_currency'];
    }

    public function businessUnits(): HasMany
    {
        return $this->hasMany(BusinessUnit::class, 'tan90_legal_entity_id');
    }
}
