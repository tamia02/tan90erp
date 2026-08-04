<?php

namespace App\Observers;

use App\Models\GrnRecord;
use App\Services\ZohoService;
use Illuminate\Support\Facades\Log;

class GrnRecordObserver
{
    public function saved(GrnRecord $record): void
    {
        try {
            app(ZohoService::class)->pushGrnRecord($record);
        } catch (\Throwable $exception) {
            Log::warning('Zoho note push failed from GrnRecord observer', ['grn_record_id' => $record->id, 'error' => $exception->getMessage()]);
        }
    }
}
