<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DashboardTemplate extends Model
{
    protected $fillable = ['uuid', 'name', 'owner_type', 'owner_id', 'parent_template_id', 'status', 'version', 'responsive_layouts_json', 'created_by', 'published_by', 'published_at'];

    protected function casts(): array
    {
        return ['responsive_layouts_json' => 'array', 'published_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DashboardTemplateItem::class, 'template_id');
    }
}
