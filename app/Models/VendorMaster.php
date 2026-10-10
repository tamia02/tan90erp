<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'vendor_name', 'gst_number', 'contact_phone', 'contact_email', 'category', 'payment_terms', 'active',
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
            'zoho_address_checked_at' => 'datetime',
        ];
    }

    // Pickers (PO vendor search, Terms & Notes) list only vendors with an
    // address, so a PO never shows "City not mapped". The vendor appears once
    // its address arrives from Zoho; Vendors master still lists everyone.
    public function scopeWithAddress($query)
    {
        return $query->whereNotNull('address_city')->where('address_city', '!=', '');
    }
}
