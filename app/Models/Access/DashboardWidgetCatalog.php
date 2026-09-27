<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;

class DashboardWidgetCatalog extends Model
{
    protected $table = 'dashboard_widget_catalog';

    protected $fillable = ['key', 'module', 'title', 'description', 'permission_key', 'provider_class', 'supported_variants_json', 'settings_schema_json', 'min_w', 'min_h', 'max_w', 'max_h', 'default_w', 'default_h', 'supports_refresh', 'status'];

    protected function casts(): array
    {
        return ['supported_variants_json' => 'array', 'settings_schema_json' => 'array', 'supports_refresh' => 'boolean'];
    }
}
