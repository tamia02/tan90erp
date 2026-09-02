<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['po_number', 'vendor_name', 'accepted', 'remarks', 'acknowledged_by', 'acknowledged_at'])]
class PurchaseOrderAcknowledgement extends Model
{
    protected function casts(): array
    {
        return [
            'accepted' => 'boolean',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
