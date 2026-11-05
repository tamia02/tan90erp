<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Model;

class EddRevision extends Model
{
    protected $table = 'logistics_edd_revisions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['old_edd' => 'datetime', 'new_edd' => 'datetime'];
    }
}
