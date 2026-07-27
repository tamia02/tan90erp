<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;

class AccessPermission extends Model
{
    protected $fillable = ['key', 'module', 'screen', 'category', 'action', 'label', 'description', 'route_name', 'ui_key', 'field_key', 'widget_key', 'allowed_scope_types', 'sort_order', 'is_sensitive', 'status'];

    protected function casts(): array
    {
        return ['allowed_scope_types' => 'array', 'is_sensitive' => 'boolean'];
    }
}
