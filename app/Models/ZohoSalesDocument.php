<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZohoSalesDocument extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'synced_at' => 'datetime', 'document_date' => 'date', 'total' => 'decimal:4'];
    }
}
