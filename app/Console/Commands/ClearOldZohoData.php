<?php

namespace App\Console\Commands;

use App\Services\Zoho\ZohoReadClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// One-off clean-up (user, 2026-10-10): remove items, vendors and customers
// that are not in the client's real Zoho - test records and ones pulled from
// the earlier Zoho account - plus the old Master Data item copy. Anything
// used on one of the real POs is kept. Forge / Flow / BOM costing are not
// touched. Always --dry-run first; a database backup must exist.
class ClearOldZohoData extends Command
{
    protected $signature = 'data:clear-old-zoho {--dry-run : Only show what would be deleted} {--force : Required to actually delete}';

    protected $description = 'Delete items / vendors / customers not in the real Zoho (test + old Zoho account); keep anything on a real PO.';

    public function handle(ZohoReadClient $zoho): int
    {
        if (! $zoho->configured()) {
            $this->error('Zoho is not configured - cannot tell real records from old ones. Nothing deleted.');

            return self::FAILURE;
        }
        $norm = fn ($s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', (string) $s))));
        $this->info('Reading vendors and customers from the real Zoho (read-only)...');
        $zohoVendors = collect($zoho->all('/contacts', 'contacts', ['contact_type' => 'vendor']))->pluck('contact_name')->map($norm)->flip();
        $zohoCustomers = collect($zoho->all('/contacts', 'contacts', ['contact_type' => 'customer']))->pluck('contact_name')->map($norm)->flip();
        if ($zohoVendors->count() < 100 || $zohoCustomers->count() < 100) {
            $this->error('Zoho returned too few contacts - stopping to be safe.');

            return self::FAILURE;
        }

        // Anything on a kept (real) PO stays.
        $poVendors = DB::table('purchase_orders')->pluck('vendor_name')->map($norm)->flip();
        $poProducts = DB::table('purchase_order_lines')->pluck('product')->map($norm)->flip();

        $realSkus = DB::table('sku_masters')->whereNotNull('zoho_item_id')->pluck('sku')->map($norm)->flip();
        $skuIds = $this->ids(DB::table('sku_masters')->whereNull('zoho_item_id')->get(['id', 'sku']), fn ($r) => ! $poProducts->has($norm($r->sku)));
        $invIds = $this->ids(DB::table('inventory_items')->get(['id', 'sku']), fn ($r) => ! $realSkus->has($norm($r->sku)) && ! $poProducts->has($norm($r->sku)));
        $bomIds = DB::table('inventory_bom_lines')->whereIn('fg_item_id', $invIds)->orWhereIn('component_item_id', $invIds)->pluck('id');
        $oldItemIds = DB::table('tan90_items')->pluck('id');
        $vendorIds = $this->ids(DB::table('vendor_masters')->get(['id', 'vendor_name']), fn ($r) => ! $zohoVendors->has($norm($r->vendor_name)) && ! $poVendors->has($norm($r->vendor_name)));
        $mdVendorIds = $this->ids(DB::table('tan90_vendors')->get(['id', 'name']), fn ($r) => ! $zohoVendors->has($norm($r->name)) && ! $poVendors->has($norm($r->name)));
        $customerIds = $this->ids(DB::table('tan90_customers')->get(['id', 'name']), fn ($r) => ! $zohoCustomers->has($norm($r->name)));

        $this->table(['Will delete', 'Rows'], [
            ['Items not in real Zoho (sku_masters)', $skuIds->count()],
            ['Inventory items not in real Zoho', $invIds->count()],
            ['Recipe lines using them', $bomIds->count()],
            ['Old Master Data item copy (tan90_items)', $oldItemIds->count()],
            ['Vendors not in real Zoho (vendor_masters)', $vendorIds->count()],
            ['Master Data vendors not in real Zoho', $mdVendorIds->count()],
            ['Customers not in real Zoho', $customerIds->count()],
        ]);
        $this->line('Items: '.DB::table('sku_masters')->whereIn('id', $skuIds)->limit(40)->pluck('sku')->implode(' | ').($skuIds->count() > 40 ? ' ...' : ''));
        $this->line('Vendors: '.DB::table('vendor_masters')->whereIn('id', $vendorIds)->pluck('vendor_name')->implode(' | '));
        $this->line('Customers: '.DB::table('tan90_customers')->whereIn('id', $customerIds)->pluck('name')->implode(' | '));

        if ($this->option('dry-run')) {
            $this->info('Dry run - nothing deleted.');

            return self::SUCCESS;
        }
        if (! $this->option('force')) {
            $this->error('Add --force to delete (after taking a database backup).');

            return self::FAILURE;
        }

        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($skuIds, $invIds, $bomIds, $oldItemIds, $vendorIds, $mdVendorIds, $customerIds) {
                DB::table('inventory_bom_lines')->whereIn('id', $bomIds)->delete();
                DB::table('inventory_items')->whereIn('id', $invIds)->delete();
                DB::table('sku_masters')->whereIn('id', $skuIds)->delete();
                DB::table('tan90_item_uom_conversions')->whereIn('tan90_item_id', $oldItemIds)->delete();
                DB::table('tan90_items')->whereIn('id', $oldItemIds)->delete();
                DB::table('vendor_masters')->whereIn('id', $vendorIds)->delete();
                DB::table('tan90_vendor_contacts')->whereIn('tan90_vendor_id', $mdVendorIds)->delete();
                DB::table('tan90_vendors')->whereIn('id', $mdVendorIds)->delete();
                DB::table('tan90_customers')->whereIn('id', $customerIds)->delete();
                DB::table('custom_field_values')->where('entity', 'master:vendors')->whereIn('record_id', $mdVendorIds)->delete();
                DB::table('custom_field_values')->where('entity', 'master:customers')->whereIn('record_id', $customerIds)->delete();
                foreach ([
                    'App\\Models\\SkuMaster' => $skuIds, 'App\\Models\\VendorMaster' => $vendorIds,
                    'App\\Models\\Tan90\\MasterData\\Vendor' => $mdVendorIds, 'App\\Models\\Tan90\\MasterData\\Customer' => $customerIds,
                    'App\\Models\\Tan90\\MasterData\\Item' => $oldItemIds,
                ] as $type => $ids) {
                    DB::table('zoho_entity_links')->where('syncable_type', $type)->whereIn('syncable_id', $ids)->delete();
                    DB::table('tan90_data_quality_issues')->where('entity_type', $type)->whereIn('entity_id', $ids)->delete();
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->info('Old / test items, vendors and customers deleted.');

        return self::SUCCESS;
    }

    private function ids(Collection $rows, callable $drop): Collection
    {
        return $rows->filter($drop)->pluck('id')->values();
    }
}
