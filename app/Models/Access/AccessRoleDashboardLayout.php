<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;

class AccessRoleDashboardLayout extends Model
{
    protected $fillable = ['role_id', 'widget_key', 'x', 'y', 'w', 'h', 'visible', 'locked', 'mandatory', 'config_json'];

    protected function casts(): array
    {
        return ['visible' => 'boolean', 'locked' => 'boolean', 'mandatory' => 'boolean', 'config_json' => 'array'];
    }
}
