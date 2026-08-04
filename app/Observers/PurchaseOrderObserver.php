<?php

namespace App\Observers;

use App\Models\PurchaseOrder;
use App\Services\ZohoService;
use Illuminate\Support\Facades\Log;

class PurchaseOrderObserver
{
    public function saved(PurchaseOrder $purchaseOrder): void
    {
        $this->push($purchaseOrder);
    }

    private function push(PurchaseOrder $purchaseOrder): void
    {
        if (! config('services.zoho.write_enabled', true)) {
            return;
        }

        try {
            $result = app(ZohoService::class)->pushPurchaseOrder($purchaseOrder->fresh('lines'));

            if (! $result['success']) {
                Log::warning('Zoho outbound PO sync failed', [
                    'po_number' => $purchaseOrder->po_number,
                    'message' => $result['message'],
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Zoho outbound PO sync exception', [
                'po_number' => $purchaseOrder->po_number,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
