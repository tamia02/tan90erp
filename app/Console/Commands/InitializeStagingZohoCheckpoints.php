<?php

namespace App\Console\Commands;

use App\Models\FinanceRecord;
use App\Models\GrnRecord;
use App\Models\PurchaseOrder;
use App\Models\SkuMaster;
use App\Models\VendorMaster;
use App\Models\VendorSubmission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class InitializeStagingZohoCheckpoints extends Command
{
    protected $signature = 'zoho:initialize-staging-checkpoints';

    protected $description = 'Set Zoho Inventory push cursors to the current staging snapshot; never sends an API request.';

    public function handle(): int
    {
        if (! app()->environment('staging')) {
            $this->error('This command may run only in the staging environment.');

            return self::FAILURE;
        }

        $entities = [
            'vendors' => VendorMaster::class,
            'items' => SkuMaster::class,
            'purchase_orders' => PurchaseOrder::class,
            'vendor_bills' => VendorSubmission::class,
            'finance_bills' => FinanceRecord::class,
            'purchase_receives' => GrnRecord::class,
        ];

        foreach ($entities as $key => $model) {
            $cacheKey = "zoho_inventory_push_checkpoint:{$key}";
            $last = $model::query()->orderByDesc('updated_at')->orderByDesc('id')->first(['id', 'updated_at']);
            $cursor = $last
                ? ['at' => $last->updated_at?->toDateTimeString(), 'id' => (int) $last->id]
                : ['at' => '1970-01-01 00:00:00', 'id' => 0];

            if (Cache::add($cacheKey, $cursor, now()->addYears(10))) {
                $this->line("Initialized {$key} checkpoint to staged database baseline.");
            } else {
                $this->line("Kept existing {$key} checkpoint.");
            }
        }

        $this->info('Checkpoint initialization complete. No Zoho calls were made.');

        return self::SUCCESS;
    }
}
