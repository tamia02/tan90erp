<?php

namespace App\Console\Commands;

use App\Services\ZohoService;
use Illuminate\Console\Command;

class PushZohoWorkflowData extends Command
{
    protected $signature = 'zoho:push-workflow-data';

    protected $description = 'Push Tan90 RFQs, vendor bills, and finance invoices into Zoho CRM Quotes and Invoices.';

    public function handle(ZohoService $zoho): int
    {
        // Zoho CRM not connected: nothing to push - skip cleanly instead of
        // reporting every record as failed every 30 minutes.
        if (! $zoho->isConfigured()) {
            $this->info('Skipped: Zoho CRM is not configured.');

            return self::SUCCESS;
        }

        $result = $zoho->pushWorkflowData();

        $this->info("Zoho workflow push complete: {$result['rfqs']} RFQs, {$result['vendor_bills']} vendor bills, {$result['finance']} finance records, {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
