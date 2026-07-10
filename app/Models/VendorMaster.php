<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'vendor_name', 'gst_number', 'contact_phone', 'contact_email', 'category', 'active',
    'vendor_owner', 'website', 'gl_account', 'email_opt_out',
    'address_country', 'address_building', 'address_street', 'address_city', 'address_state', 'address_zip',
    'description',
])]
class VendorMaster extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'email_opt_out' => 'boolean',
        ];
    }
}
