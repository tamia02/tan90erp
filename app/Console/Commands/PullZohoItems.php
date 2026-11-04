<?php

namespace App\Console\Commands;

use App\Services\Zoho\ZohoItemPullService;
use Illuminate\Console\Command;

class PullZohoItems extends Command
{
    protected $signature = 'zoho:pull-items {--dry-run : Count and classify only, write nothing}';

    protected $description = 'Read every Zoho Inventory item (active + inactive) into SKU Master and Inventory. Zoho is the source of truth; GET-only.';

    public function handle(ZohoItemPullService $pull): int
    {
        if (! $pull->configured()) {
            $this->warn('Skipped: Zoho item pull is not configured.');

            return self::SUCCESS;
        }

        $stats = $pull->pull((bool) $this->option('dry-run'));

        $this->info(($this->option('dry-run') ? '[dry run] ' : '')."Fetched {$stats['fetched']} Zoho items: {$stats['created']} new, {$stats['updated']} updated, {$stats['duplicate_names']} with a duplicate name (SKU added).");
        foreach ($stats['by_type'] as $type => $count) {
            $this->line("  {$type}: {$count}");
        }
        $this->line("  Zoho API calls: {$stats['api_calls']}");

        return self::SUCCESS;
    }
}
