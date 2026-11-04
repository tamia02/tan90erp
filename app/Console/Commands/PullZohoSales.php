<?php

namespace App\Console\Commands;

use App\Services\Zoho\ZohoSalesPullService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PullZohoSales extends Command
{
    protected $signature = 'zoho:pull-sales {--max-pages=10 : Pages per module per run} {--module= : Only salesorders, packages or invoices} {--dry-run : Fetch without saving records or progress}';

    protected $description = 'Import Zoho sales orders, package numbers and sales invoices into ERP.';

    public function handle(ZohoSalesPullService $pull): int
    {
        $pages = filter_var($this->option('max-pages'), FILTER_VALIDATE_INT);
        if (! $pages || $pages < 1 || $pages > 100) {
            $this->error('--max-pages must be between 1 and 100.');

            return self::FAILURE;
        }
        $lock = Cache::lock('zoho:sales-pull:'.config('services.zoho_items_pull.organization_id'), 14400);
        if (! $lock->get()) {
            $this->warn('Sales import is already running.');

            return self::FAILURE;
        }
        try {
            foreach ($pull->pull($pages, (bool) $this->option('dry-run'), $this->option('module')) as $module => $stats) {
                $this->info(($this->option('dry-run') ? '[dry run] ' : '')."{$module}: {$stats['imported']} fetched".($stats['more'] ? ' (more pending; rerun to continue)' : ' (scan complete)'));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
