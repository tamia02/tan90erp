<?php

namespace App\Console\Commands;

use App\Services\ZohoInventoryService;
use Illuminate\Console\Command;

class PushZohoInventoryData extends Command
{
    protected $signature = 'zoho:push-inventory-data';

    protected $description = 'Push Tan90 SKU/Vendor Master, Purchase Orders, vendor bills, and GRN receiving into Zoho Inventory (Items, Contacts, Purchase Orders, Bills, Purchase Receives).';

    public function handle(ZohoInventoryService $inventory): int
    {
        $result = $inventory->pushOperationalData();

        if ($result['skipped']) {
            $this->info('Zoho Inventory push skipped — organization_id and/or refresh token not configured yet.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Zoho Inventory push complete: %d items, %d vendors, %d purchase orders, %d bills, %d purchase receives, %d failed.',
            $result['items'],
            $result['vendors'],
            $result['purchase_orders'],
            $result['bills'],
            $result['purchase_receives'],
            $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
