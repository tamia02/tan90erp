<?php

namespace App\Observers;

use App\Models\DebitNote;
use App\Services\ZohoInventoryService;
use Illuminate\Support\Facades\Log;

class DebitNoteObserver
{
    public function created(DebitNote $note): void
    {
        $inventory = app(ZohoInventoryService::class);

        if (! $inventory->isActive()) {
            return;
        }

        try {
            if (! $inventory->pushVendorCredit($note)) {
                Log::warning('Zoho Inventory vendor credit push failed from DebitNote observer', [
                    'debit_note_id' => $note->id,
                    'vendor_name' => $note->vendor_name,
                    'error' => $inventory->lastError(),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Zoho Inventory vendor credit push exception from DebitNote observer', [
                'debit_note_id' => $note->id,
                'vendor_name' => $note->vendor_name,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
