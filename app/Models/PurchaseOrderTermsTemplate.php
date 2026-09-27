<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['material_type', 'name', 'body', 'active'])]
class PurchaseOrderTermsTemplate extends Model
{
}
