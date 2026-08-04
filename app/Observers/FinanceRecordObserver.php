<?php

namespace App\Observers;

use App\Models\FinanceRecord;
use App\Services\ZohoService;
use Illuminate\Support\Facades\Log;

class FinanceRecordObserver
{
    public function saved(FinanceRecord $record): void
    {
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
