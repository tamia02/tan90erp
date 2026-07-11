<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'vendor_name', 'sku', 'quantity', 'notes', 'status', 'quoted_price', 'admin_notes',
])]
class Rfq extends Model
{
    protected function casts(): array
    {
        return [
            'quoted_price' => 'decimal:2',
        ];
    }
}
