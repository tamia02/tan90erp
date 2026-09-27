<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'active', 'sort_order', 'created_by'])]
class UnloadingPackingUnit extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
