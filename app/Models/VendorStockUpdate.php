<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['vendor_name', 'material', 'quantity', 'unit', 'note'])]
class VendorStockUpdate extends Model
{
    //
}
