<?php

namespace App\Http\Controllers;

use App\Services\ZohoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class ZohoWebhookController extends Controller
{
    public function purchaseOrder(Request $request, ZohoService $zoho): JsonResponse
    {
        $configuredSecret = (string) config('services.zoho.webhook_secret');
        $providedSecret = (string) ($request->header('X-Zoho-Webhook-Secret') ?: $request->query('secret'));

        if ($configuredSecret !== '' && ! hash_equals($configuredSecret, $providedSecret)) {
            return response()->json(['ok' => false, 'message' => 'Invalid webhook secret.'], 403);
        }

        $payload = $request->all();
        $poNumber = $this->firstPayloadValue($payload, ['PO_Number', 'po_number', 'poNumber', 'Purchase_Order_Number']);
        $recordId = $this->firstPayloadValue($payload, ['id', 'record_id', 'recordId', 'Purchase_Orders.id']);

        $po = null;

        if ($poNumber) {
            $po = $zoho->syncPurchaseOrder($poNumber);
        }

        if (! $po && $recordId) {
            $po = $zoho->syncPurchaseOrderById($recordId);
        }

        Log::info('Zoho PO webhook received', [
            'synced' => (bool) $po,
            'po_number' => $po?->po_number ?? $poNumber,
            'record_id' => $recordId,
        ]);

        return response()->json([
            'ok' => (bool) $po,
            'po_number' => $po?->po_number,
            'message' => $po ? 'Purchase order synced.' : 'Webhook received, but no matching PO could be synced.',
        ], $po ? 200 : 202);
    }

    /**
     * @param array<string, mixed> $payload
     * @param string[] $keys
     */
    private function firstPayloadValue(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $found = $this->firstPayloadValue($value, $keys);

                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }
}
