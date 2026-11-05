<?php

namespace App\Console\Commands;

use App\Services\Zoho\ZohoFieldPullService;
use Illuminate\Console\Command;

class PullZohoFields extends Command
{
    protected $signature = 'zoho:pull-fields';

    protected $description = 'Copy Zoho custom field definitions (labels, required flags, dropdown options) so ERP forms match Zoho. One GET call.';

    public function handle(ZohoFieldPullService $pull): int
    {
        $stats = $pull->pull();
        $this->info("Zoho fields: {$stats['fields']} across {$stats['entities']} modules ({$stats['mandatory']} required, {$stats['deactivated']} switched off), {$stats['locations']} Zoho warehouses.");

        return self::SUCCESS;
    }
}
