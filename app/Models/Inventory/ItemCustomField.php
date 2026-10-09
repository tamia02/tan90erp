<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

class ItemCustomField extends Model
{
    public const TYPES = ['text' => 'Text', 'number' => 'Number', 'dropdown' => 'Drop-down'];

    protected $fillable = ['field_key', 'label', 'input_type', 'options', 'required', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['options' => 'array', 'required' => 'boolean', 'active' => 'boolean'];
    }

    public static function activeFields()
    {
        return static::where('active', true)->orderBy('sort_order')->orderBy('id')->get();
    }
}
