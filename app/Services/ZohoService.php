<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// Real Zoho CRM integration — replaces the React prototype's Vercel/Node
// proxy, which only existed because a pure SPA has nowhere to hold an
// OAuth Client Secret. A real Laravel backend just... has a backend: the
// secret lives in .env, never reaches the browser, no proxy layer needed.
//
// Pulls from Zoho's standard Purchase Orders module — confirmed against
// the client's real org. That module has NO Invoice Number or E-way Bill
// Number field (checked directly), and Zoho's Invoices module is
// customer-facing (Account/Contact/Deal lookups, no Vendor Name) — so
// Invoice Number / E-way Bill Number stay manual, vendor-submitted fields,
// same as the React version. Every real PO seen so far is single-line-item,
// so only the first Purchase Item line is read.
class ZohoService
{
    private function accessToken(): ?string
    {
        return Cache::remember('zoho_access_token', 3300, function () {
            $accountsBase = config('services.zoho.accounts_base_url');
            $response = Http::asForm()->post("{$accountsBase}/oauth/v2/token", [
                'refresh_token' => config('services.zoho.refresh_token'),
                'client_id' => config('services.zoho.client_id'),
                'client_secret' => config('services.zoho.client_secret'),
                'grant_type' => 'refresh_token',
            ]);

            if (! $response->successful() || $response->json('error')) {
                return null;
            }

            // Cache the api_domain alongside the token so callers don't have
            // to re-derive it — stash it under a sibling cache key.
            Cache::put('zoho_api_domain', $response->json('api_domain'), 3300);

            return $response->json('access_token');
        });
    }

    /**
     * @return array{poNumber: string, vendorName: string, subject: string, material: string, quantity: int, listPrice: float}|null
     */
    public function findPurchaseOrder(string $poNumber): ?array
    {
        $token = $this->accessToken();
        $apiDomain = Cache::get('zoho_api_domain');
        $moduleApiName = config('services.zoho.po_module_api_name', 'Purchase_Orders');

        if (! $token || ! $apiDomain) {
            return null;
        }

        $poField = config('services.zoho.field_po', 'PO_Number');
        $criteria = "({$poField}:equals:{$poNumber})";

        $response = Http::withHeaders(['Authorization' => "Zoho-oauthtoken {$token}"])
            ->get("{$apiDomain}/crm/v3/{$moduleApiName}/search", ['criteria' => $criteria]);

        if ($response->status() === 204 || ! $response->successful()) {
            return null;
        }

        $record = $response->json('data.0');
        if (! $record) {
            return null;
        }

        $vendorField = $record[config('services.zoho.field_vendor_name', 'Vendor_Name')] ?? null;
        $vendorName = is_array($vendorField) ? ($vendorField['name'] ?? '') : (string) ($vendorField ?? '');

        $lines = $record[config('services.zoho.field_product_details', 'Product_Details')] ?? [];
        $primaryLine = $lines[0] ?? null;

        return [
            'poNumber' => $record[$poField] ?? $poNumber,
            'vendorName' => $vendorName,
            'subject' => $record[config('services.zoho.field_subject', 'Subject')] ?? '',
            'material' => $primaryLine['product']['name'] ?? '',
            'quantity' => (int) ($primaryLine['quantity'] ?? 0),
            'listPrice' => (float) ($primaryLine['list_price'] ?? 0),
        ];
    }
}
