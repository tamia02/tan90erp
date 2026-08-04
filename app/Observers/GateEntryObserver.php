<?php

namespace App\Observers;

use App\Models\GateEntry;
use App\Services\ZohoService;
use Illuminate\Support\Facades\Log;

class GateEntryObserver
{
    public function saved(GateEntry $entry): void
    {
        try {
            app(ZohoService::class)->pushGateEntry($entry);
        } catch (\Throwable $exception) {
            Log::warning('Zoho note push failed from GateEntry observer', ['gate_entry_id' => $entry->id, 'error' => $exception->getMessage()]);
        }
    }
}
