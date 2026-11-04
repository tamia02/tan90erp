<?php

namespace App\Console\Commands;

use App\Services\Zoho\ZohoRecipePullService;
use Illuminate\Console\Command;

class PullZohoRecipes extends Command
{
    protected $signature = 'zoho:pull-recipes {--dry-run : Only count what would be fetched} {--force : Re-fetch every recipe, changed or not}';

    protected $description = 'Copy Zoho composite item recipes (components + qty) onto Inventory Finished Goods. GET-only; run zoho:pull-items first.';

    public function handle(ZohoRecipePullService $pull): int
    {
        $stats = $pull->pull((bool) $this->option('dry-run'), (bool) $this->option('force'));

        $this->info(($this->option('dry-run') ? '[dry run] ' : '')."Zoho composite items: {$stats['composites']}");
        foreach ($stats as $key => $value) {
            if ($key !== 'composites') {
                $this->line("  {$key}: {$value}");
            }
        }

        return self::SUCCESS;
    }
}
