<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['finance_record_id', 'vendor_name', 'reason', 'amount', 'status'])]
class DebitNote extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function financeRecord(): BelongsTo
    {
        return $this->belongsTo(FinanceRecord::class);
    }
}
