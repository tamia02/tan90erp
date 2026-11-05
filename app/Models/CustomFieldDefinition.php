<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A field the team added to a table themselves (Master Data > Custom Fields).
class CustomFieldDefinition extends Model
{
    protected $fillable = ['entity', 'field_key', 'label', 'input_type', 'options', 'required', 'active', 'sort_order', 'created_by'];

    protected function casts(): array
    {
        return ['options' => 'array', 'required' => 'boolean', 'active' => 'boolean'];
    }
}
