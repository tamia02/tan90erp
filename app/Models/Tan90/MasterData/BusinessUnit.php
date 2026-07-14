<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessUnit extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_business_units';

    protected $fillable = [
        'code', 'name', 'tan90_legal_entity_id', 'head', 'cost_center',
        'description', 'status', 'approval_status',
    ];

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'tan90_legal_entity_id');
    }

    public function plants(): HasMany
    {
        return $this->hasMany(Plant::class, 'tan90_business_unit_id');
    }
}
