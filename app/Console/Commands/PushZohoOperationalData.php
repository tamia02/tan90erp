<?php

namespace App\Console\Commands;

use App\Services\ZohoService;
use Illuminate\Console\Command;

class PushZohoOperationalData extends Command
{
    protected $signature = 'zoho:push-operational-data';

    protected $description = 'Push Tan90 Gate, QC, GRN, BOM, Recipe, and Costing records into Zoho notes.';

    public function handle(ZohoService $zoho): int
    {
        $result = $zoho->pushOperationalData();

        $this->info("Zoho operational push complete: {$result['gate_entries']} gate entries, {$result['qc_results']} QC results, {$result['grn_records']} GRN records, {$result['bom_records']} BOM/Recipe/Costing records, {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
