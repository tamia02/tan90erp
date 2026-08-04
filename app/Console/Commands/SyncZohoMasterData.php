<?php

namespace App\Console\Commands;

use App\Services\ZohoService;
use Illuminate\Console\Command;

class SyncZohoMasterData extends Command
{
    protected $signature = 'zoho:sync-master-data {--limit=200 : Maximum vendors/products to read per module}';

    protected $description = 'Sync Zoho CRM Vendors and Products into Tan90 master data.';

    public function handle(ZohoService $zoho): int
    {
        $result = $zoho->syncMasterData((int) $this->option('limit'));

        $this->info("Zoho master-data sync complete: {$result['vendors']} vendors, {$result['products']} products, {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
