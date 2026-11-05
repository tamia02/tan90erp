<?php

use App\Models\PurchaseOrder;
use App\Models\VendorMaster;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        VendorMaster::query()->whereNull('payment_terms')->orderBy('id')->get()->each(function (VendorMaster $vendor): void {
            $terms = PurchaseOrder::query()
                ->where('vendor_name', $vendor->vendor_name)
                ->whereNotNull('payment_terms')
                ->where('payment_terms', '!=', '')
                ->latest('created_at')
                ->value('payment_terms');

            if ($terms) {
                $vendor->forceFill(['payment_terms' => $terms])->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        // Backfill only; do not erase values that may have been maintained in
        // the vendor master after this migration ran.
    }
};
