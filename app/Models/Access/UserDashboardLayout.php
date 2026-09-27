<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;

class UserDashboardLayout extends Model
{
    protected $fillable = ['user_id', 'widget_key', 'x', 'y', 'w', 'h', 'visible', 'config_json'];

    protected function casts(): array
    {
        return ['visible' => 'boolean', 'config_json' => 'array'];
    }
}
