<?php

namespace App\Observers;

use App\Models\PurchaseOrderLine;
use App\Services\ZohoInventoryService;
use App\Services\ZohoService;
use Illuminate\Support\Facades\Log;

class PurchaseOrderLineObserver
{
    public function saved(PurchaseOrderLine $line): void
    {
        $this->pushParent($line);
    }

    public function deleted(PurchaseOrderLine $line): void
    {
        $this->pushParent($line);
    }

    private function pushParent(PurchaseOrderLine $line): void
    {
        $po = $line->purchaseOrder()->with('lines')->first();

        if (! $po) {
            return;
        }

        if (app(ZohoInventoryService::class)->isActive()) {
            if (! config('services.zoho.inventory.write_enabled', true)) {
                return;
            }

            try {
                $result = app(ZohoInventoryService::class)->pushPurchaseOrder($po);

                if (! $result['success']) {
                    Log::warning('Zoho Inventory outbound PO line sync failed', [
                        'po_number' => $po->po_number,
                        'message' => $result['message'],
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Zoho Inventory outbound PO line sync exception', [
                    'po_number' => $po->po_number,
                    'message' => $e->getMessage(),
                ]);
            }

            return;
        }

        // CRM stays the PO sync until Zoho Inventory is active.
        if (! config('services.zoho.write_enabled', true)) {
            return;
        }

        try {
            $result = app(ZohoService::class)->pushPurchaseOrder($po);

            if (! $result['success']) {
                Log::warning('Zoho outbound PO line sync failed', [
                    'po_number' => $po->po_number,
                    'message' => $result['message'],
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Zoho outbound PO line sync exception', [
                'po_number' => $po->po_number,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
