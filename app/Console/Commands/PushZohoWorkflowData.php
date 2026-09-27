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
        $result = $zoho->pushWorkflowData();

        $this->info("Zoho workflow push complete: {$result['rfqs']} RFQs, {$result['vendor_bills']} vendor bills, {$result['finance']} finance records, {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
