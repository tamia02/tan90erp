<?php

namespace App\Console\Commands;

use App\Services\ZohoService;
use Illuminate\Console\Command;

class PushZohoMasterData extends Command
{
    protected $signature = 'zoho:push-master-data';

    protected $description = 'Push Tan90 Vendor Master and SKU Master records into Zoho CRM Vendors and Products.';

    public function handle(ZohoService $zoho): int
    {
        $result = $zoho->pushMasterData();

        $this->info("Zoho master-data push complete: {$result['vendors']} vendors, {$result['products']} products, {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
