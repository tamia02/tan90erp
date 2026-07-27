<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;

class AccessSavedView extends Model
{
    protected $fillable = ['uuid', 'key', 'module', 'screen_key', 'name', 'description', 'owner_user_id', 'owner_level', 'base_view_key', 'columns_json', 'filters_json', 'sort_json', 'group_json', 'display_json', 'row_actions_json', 'locked_parts_json', 'status', 'version'];

    protected function casts(): array
    {
        return ['columns_json' => 'array', 'filters_json' => 'array', 'sort_json' => 'array', 'group_json' => 'array', 'display_json' => 'array', 'row_actions_json' => 'array', 'locked_parts_json' => 'array'];
    }
}
