<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;

class DashboardWidget extends Model
{
    protected $fillable = ['key', 'module', 'title', 'description', 'permission_key', 'provider_class', 'min_w', 'max_w', 'default_w', 'min_h', 'max_h', 'default_h', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
