<?php

namespace App\Models\Inventory;

use App\Models\User;
use App\Support\CustomFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// Inventory Items' custom fields - the 'inventory_items' rows of the shared
// custom_field_definitions table (Master Data > Custom Fields manages all
// tables). Values stay on inventory_items.custom_fields.
class ItemCustomField extends Model
{
    public const TYPES = CustomFields::TYPES;

    public const PERMISSION = 'inventory.custom_fields.manage';

    protected $table = 'custom_field_definitions';

    protected $fillable = ['entity', 'field_key', 'label', 'input_type', 'options', 'required', 'active', 'sort_order', 'created_by'];

    protected static function booted(): void
    {
        static::addGlobalScope('inventory_items', fn (Builder $q) => $q->where('entity', CustomFields::INVENTORY_ITEMS));
        static::creating(fn (self $field) => $field->entity = CustomFields::INVENTORY_ITEMS);
    }

    protected function casts(): array
    {
        return ['options' => 'array', 'required' => 'boolean', 'active' => 'boolean'];
    }

    public static function activeFields()
    {
        return static::where('active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    // Item fields: the older item-only permission, or the all-tables one.
    public static function canManage(?User $user): bool
    {
        return \App\Support\GrantedPermission::has($user, self::PERMISSION) || CustomFields::canManage($user);
    }
}
