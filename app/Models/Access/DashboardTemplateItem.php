<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;

class DashboardTemplateItem extends Model
{
    protected $fillable = ['template_id', 'widget_key', 'page_key', 'tab_key', 'x', 'y', 'w', 'h', 'mobile_x', 'mobile_y', 'mobile_w', 'mobile_h', 'visible', 'mandatory', 'position_locked', 'size_locked', 'config_locked', 'config_json', 'sort_order'];

    protected function casts(): array
    {
        return ['visible' => 'boolean', 'mandatory' => 'boolean', 'position_locked' => 'boolean', 'size_locked' => 'boolean', 'config_locked' => 'boolean', 'config_json' => 'array'];
    }
}
