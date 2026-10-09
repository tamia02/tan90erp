<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ItemCustomField extends Model
{
    public const TYPES = ['text' => 'Text', 'number' => 'Number', 'dropdown' => 'Drop-down'];

    public const PERMISSION = 'inventory.custom_fields.manage';

    protected $fillable = ['field_key', 'label', 'input_type', 'options', 'required', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['options' => 'array', 'required' => 'boolean', 'active' => 'boolean'];
    }

    public static function activeFields()
    {
        return static::where('active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    public static function canManage(?User $user): bool
    {
        return \App\Support\GrantedPermission::has($user, self::PERMISSION);
    }
}
