<?php

namespace App\Observers\Tan90;

use App\Models\Tan90\MasterData\Customer;
use App\Services\ZohoInventoryService;
use Illuminate\Support\Facades\Log;

class MasterDataCustomerObserver
{
    public function saved(Customer $customer): void
    {
        $inventory = app(ZohoInventoryService::class);

        if (! $inventory->isActive()) {
            return;
        }

        try {
            if (! $inventory->pushMasterDataCustomer($customer)) {
                Log::warning('Zoho Inventory customer push failed from Master Data Customer observer', [
                    'customer_id' => $customer->id,
                    'name' => $customer->name,
                    'error' => $inventory->lastError(),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Zoho Inventory customer push exception from Master Data Customer observer', [
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
