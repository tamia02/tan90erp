<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use App\Services\ZohoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PushZohoPurchaseOrders extends Command
{
    protected $signature = 'zoho:push-purchase-orders {--fresh : Ignore the saved checkpoint and push local records again}';

    protected $description = 'Push locally modified Tan90 purchase orders into Zoho CRM as the outbound backup sync.';

    public function handle(ZohoService $zoho): int
    {
        $checkpointKey = 'zoho_purchase_orders_outbound_last_modified';
        $since = $this->option('fresh') ? null : Cache::get($checkpointKey);

        $query = PurchaseOrder::with('lines')->orderBy('updated_at');

        if ($since) {
            $query->where('updated_at', '>', $since);
        }

        $synced = 0;
        $failed = 0;
        $latest = $since;

        $query->chunk(50, function ($orders) use ($zoho, &$synced, &$failed, &$latest) {
            foreach ($orders as $po) {
                $result = $zoho->pushPurchaseOrder($po);
                $result['success'] ? $synced++ : $failed++;
                $latest = $po->updated_at?->toDateTimeString() ?? $latest;

                if (! $result['success']) {
                    $this->warn("{$po->po_number}: {$result['message']}");
                }
            }
        });

        if ($latest && $failed === 0) {
            Cache::forever($checkpointKey, $latest);
        }

        $this->info("Zoho outbound PO push complete: {$synced} synced, {$failed} failed. Checkpoint: ".($failed === 0 ? ($latest ?: 'none') : 'unchanged due to failures'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
