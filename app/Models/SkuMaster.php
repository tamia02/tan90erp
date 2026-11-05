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
    'zoho_item_id', 'zoho_item_type', 'zoho_is_composite', 'zoho_tags', 'zoho_hsn', 'zoho_synced_at', 'zoho_recipe_modified_at',
    'intra_state_tax_name', 'intra_state_tax_rate', 'intra_state_tax_type',
    'inter_state_tax_name', 'inter_state_tax_rate', 'inter_state_tax_type', 'taxability_type', 'tax_exemption_reason',
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
            'zoho_is_composite' => 'boolean',
            'zoho_synced_at' => 'datetime',
            'sales_start_date' => 'date',
            'sales_end_date' => 'date',
            'support_start_date' => 'date',
            'support_end_date' => 'date',
            'unit_price' => 'decimal:2',
            'tax' => 'decimal:2',
            'intra_state_tax_rate' => 'decimal:3',
            'inter_state_tax_rate' => 'decimal:3',
            'commission_rate' => 'decimal:2',
        ];
    }
}
