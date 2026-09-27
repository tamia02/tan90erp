<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'vendor_name', 'sku', 'quantity', 'notes', 'status', 'quoted_price', 'admin_notes',
    'technical_score', 'commercial_score', 'evaluated_by', 'evaluated_at',
])]
class Rfq extends Model
{
    // 60/40 technical/commercial split — a reasonable default weighting for a
    // material procurement decision where fitness-for-use matters more than
    // price alone, and simple enough to explain to whoever's approving the award.
    private const TECHNICAL_WEIGHT = 0.6;

    private const COMMERCIAL_WEIGHT = 0.4;

    protected function casts(): array
    {
        return [
            'quoted_price' => 'decimal:2',
            'evaluated_at' => 'datetime',
        ];
    }

    public function weightedScore(): ?float
    {
        if ($this->technical_score === null || $this->commercial_score === null) {
            return null;
        }

        return round($this->technical_score * self::TECHNICAL_WEIGHT + $this->commercial_score * self::COMMERCIAL_WEIGHT, 1);
    }
}
