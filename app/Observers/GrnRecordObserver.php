<?php

namespace App\Observers;

use App\Models\GrnRecord;
use App\Services\ZohoInventoryService;
use App\Services\ZohoService;
use Illuminate\Support\Facades\Log;

class GrnRecordObserver
{
    public function saved(GrnRecord $record): void
    {
        // GRN gets a real structured home in Zoho Inventory (Purchase
        // Receives against the PO) instead of a CRM note — once Inventory
        // is active it fully replaces the CRM note push for GRN.
        if (app(ZohoInventoryService::class)->isActive()) {
            try {
                app(ZohoInventoryService::class)->pushPurchaseReceive($record);
            } catch (\Throwable $exception) {
                Log::warning('Zoho Inventory purchase receive push failed from GrnRecord observer', [
                    'grn_record_id' => $record->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            return;
        }

        try {
            app(ZohoService::class)->pushGrnRecord($record);
        } catch (\Throwable $exception) {
            Log::warning('Zoho note push failed from GrnRecord observer', ['grn_record_id' => $record->id, 'error' => $exception->getMessage()]);
        }
    }
}
