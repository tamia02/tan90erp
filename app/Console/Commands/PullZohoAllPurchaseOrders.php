<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use App\Services\Zoho\ZohoReadClient;
use App\Services\ZohoInventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

// Brings every Zoho PO that is not in the ERP yet (read-only, newest first).
// POs already in the ERP are left alone. Each PO needs one Zoho call for its
// lines, so a run stops after --max of them to keep within the daily API limit;
// the next run carries on where this one stopped.
class PullZohoAllPurchaseOrders extends Command
{
    protected $signature = 'zoho:pull-all-pos {--max=1500 : Most POs to fetch in this run} {--dry-run : Only count what is missing}';

    protected $description = 'Pull Zoho purchase orders that are missing in the ERP, in daily batches (read-only).';

    public function handle(ZohoReadClient $zoho, ZohoInventoryService $inventory): int
    {
        if (! $zoho->configured()) {
            $this->error('Zoho is not configured.');

            return self::FAILURE;
        }
        $lock = Cache::lock('zoho:pull-all-pos', 4 * 3600);
        if (! $lock->get()) {
            $this->warn('Another run is still going.');

            return self::SUCCESS;
        }

        try {
            $zoho->pauseMs = app()->runningUnitTests() ? 0 : 1100; // about 55 calls a minute, under Zoho's per-minute limit
            $rows = $zoho->all('/purchaseorders', 'purchaseorders', ['sort_column' => 'date', 'sort_order' => 'D'], 100);
            $have = PurchaseOrder::pluck('po_number')->map(fn ($n) => mb_strtolower($n))->flip();
            $missing = collect($rows)->filter(fn ($r) => filled($r['purchaseorder_number'] ?? null) && ! $have->has(mb_strtolower($r['purchaseorder_number'])))->values();

            $this->info('Zoho POs: '.count($rows).', already in the ERP: '.(count($rows) - $missing->count()).', missing: '.$missing->count().'.');
            $this->line('Missing by Zoho status: '.$missing->countBy(fn ($r) => $r['status'] ?? '?')->map(fn ($c, $s) => "{$s} {$c}")->implode(', '));
            if ($this->option('dry-run') || $missing->isEmpty()) {
                return self::SUCCESS;
            }

            $done = 0;
            $failed = 0;
            foreach ($missing->take((int) $this->option('max')) as $row) {
                try {
                    $doc = $zoho->get("/purchaseorders/{$row['purchaseorder_id']}")['purchaseorder'] ?? null;
                    $doc && $inventory->importPurchaseOrder($doc) ? $done++ : $failed++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn("{$row['purchaseorder_number']}: ".$e->getMessage());
                    if (preg_match('/\((429|400)\)|limit|exceeded/i', $e->getMessage())) {
                        $this->warn('Zoho limit reached - stopping; the next run continues.');
                        break;
                    }
                }
                if ($done && $done % 100 === 0) {
                    $this->line("... {$done} added");
                }
            }
            $left = $missing->count() - $done;
            $this->info("Added {$done} POs, {$failed} failed, {$left} still to bring. Zoho calls used: {$zoho->calls}.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
