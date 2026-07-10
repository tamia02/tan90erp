<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'sku', 'category', 'unit', 'default_bin', 'mapped',
    'product_owner', 'product_code', 'active', 'vendor_name', 'manufacturer',
    'sales_start_date', 'sales_end_date', 'support_start_date', 'support_end_date',
    'unit_price', 'tax', 'commission_rate', 'taxable',
    'quantity_in_stock', 'handler', 'qty_ordered', 'reorder_level', 'quantity_in_demand',
    'description',
])]
class SkuMaster extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'mapped' => 'boolean',
            'active' => 'boolean',
            'taxable' => 'boolean',
            'sales_start_date' => 'date',
            'sales_end_date' => 'date',
            'support_start_date' => 'date',
            'support_end_date' => 'date',
            'unit_price' => 'decimal:2',
            'tax' => 'decimal:2',
            'commission_rate' => 'decimal:2',
        ];
    }
}
