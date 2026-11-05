<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['scope', 'field_key', 'label', 'input_type', 'material_type', 'active', 'sort_order'])]
class PurchaseOrderCustomFieldDefinition extends Model
{
}
