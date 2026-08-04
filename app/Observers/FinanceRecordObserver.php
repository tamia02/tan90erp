<?php

namespace App\Observers;

use App\Models\FinanceRecord;
use App\Services\ZohoInventoryService;
use App\Services\ZohoService;
use Illuminate\Support\Facades\Log;

class FinanceRecordObserver
{
    public function saved(FinanceRecord $record): void
    {
        $inventoryActive = app(ZohoInventoryService::class)->isActive();

        if ($inventoryActive) {
            try {
                app(ZohoInventoryService::class)->pushFinanceBill($record);
            } catch (\Throwable $exception) {
                Log::warning('Zoho Inventory bill push failed from FinanceRecord observer', [
                    'finance_record_id' => $record->id,
                    'invoice_number' => $record->invoice_number,
                    'error' => $exception->getMessage(),
                ]);
            }

            return;
        }

        // CRM stays the finance/bill sync until Zoho Inventory is active.
        try {
            app(ZohoService::class)->pushFinanceRecord($record);
        } catch (\Throwable $exception) {
            Log::warning('Zoho Invoice push failed from FinanceRecord observer', [
                'finance_record_id' => $record->id,
                'invoice_number' => $record->invoice_number,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
