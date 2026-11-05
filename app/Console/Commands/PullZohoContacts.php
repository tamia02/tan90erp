<?php

namespace App\Console\Commands;

use App\Services\ZohoInventoryService;
use Illuminate\Console\Command;

class PullZohoContacts extends Command
{
    protected $signature = 'zoho:pull-contacts';

    protected $description = 'One-off backfill: read EVERY Zoho vendor and customer (all pages) into Vendor / Customer Master. Read-only towards Zoho.';

    public function handle(ZohoInventoryService $inventory): int
    {
        if (! $inventory->isActive()) {
            $this->info('Skipped - Zoho Inventory is not active.');

            return self::SUCCESS;
        }

        $result = $inventory->pullAllContacts(fn ($kind, $page, $count) => $this->line("{$kind} page {$page}: {$count}"));
        $this->info("Pulled {$result['vendors']} vendors and {$result['customers']} customers from Zoho.");

        return self::SUCCESS;
    }
}
