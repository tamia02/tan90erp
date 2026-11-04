<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZohoLocation extends Model
{
    protected $fillable = ['zoho_location_id', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
