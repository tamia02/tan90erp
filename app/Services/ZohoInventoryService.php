<?php

namespace App\Services;

use App\Models\FinanceRecord;
use App\Models\GrnRecord;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SkuMaster;
use App\Models\VendorMaster;
use App\Models\VendorSubmission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// Zoho Inventory integration — the operational counterpart to ZohoService
// (which talks to Zoho CRM). CRM has no real Items, Purchase Orders, Bills,
// or Purchase Receives model; Inventory does, and that's where stock,
// receiving, and vendor billing actually belong. Every Inventory API call
// requires organization_id as a query param, unlike CRM.
//
// Inactive (every push a safe no-op) until both organization_id and an
// Inventory-scoped refresh token are configured — see .env.example. Ships
// this way on purpose so the code can land before the client's Zoho
// Inventory access is confirmed, and switch on the moment it is.
class ZohoInventoryService
{
    private ?string $lastError = null;

    public function isActive(): bool
    {
        return (bool) config('services.zoho.inventory.organization_id')
            && (bool) config('services.zoho.inventory.refresh_token');
    }

    private function accessToken(): ?string
    {
        if (! $this->isActive()) {
            return null;
        }

        return Cache::remember('zoho_inventory_access_token', 3300, function () {
            $accountsBase = config('services.zoho.accounts_base_url');
            $response = Http::asForm()->post("{$accountsBase}/oauth/v2/token", [
                'refresh_token' => config('services.zoho.inventory.refresh_token'),
                'client_id' => config('services.zoho.inventory.client_id'),
                'client_secret' => config('services.zoho.inventory.client_secret'),
                'grant_type' => 'refresh_token',
            ]);

            if (! $response->successful() || $response->json('error')) {
                return null;
            }

            return $response->json('access_token');
        });
    }

    private function orgId(): ?string
    {
        return config('services.zoho.inventory.organization_id') ?: null;
    }

    private function apiBase(): string
    {
        return rtrim((string) config('services.zoho.inventory.api_base_url'), '/');
    }

    private function inventoryRequest()
    {
        $token = $this->accessToken();

        return Http::withHeaders(['Authorization' => "Zoho-oauthtoken {$token}"]);
    }

    /** Every Inventory endpoint needs organization_id on the query string, including POST/PUT — bake it into the URL so every verb gets it the same way. */
    private function invUrl(string $path, array $extraQuery = []): string
    {
        $query = array_merge(['organization_id' => $this->orgId()], $extraQuery);

        return "{$this->apiBase()}{$path}?".http_build_query($query);
    }

    /**
     * @return array{items: int, vendors: int, purchase_orders: int, bills: int, purchase_receives: int, failed: int, skipped: bool}
     */
    public function pushOperationalData(int $limit = 200): array
    {
        if (! $this->isActive()) {
            return ['items' => 0, 'vendors' => 0, 'purchase_orders' => 0, 'bills' => 0, 'purchase_receives' => 0, 'failed' => 0, 'skipped' => true];
        }

        $vendorResult = $this->pushChangedSince(VendorMaster::class, 'vendors', $limit, null, fn ($vendor) => $this->pushVendorContact($vendor));
        $itemResult = $this->pushChangedSince(SkuMaster::class, 'items', $limit, null, fn ($sku) => $this->pushItem($sku));
        $poResult = $this->pushChangedSince(PurchaseOrder::class, 'purchase_orders', $limit, fn ($query) => $query->with('lines'), fn ($po) => $this->pushPurchaseOrder($po)['success']);
        $vendorBillResult = $this->pushChangedSince(VendorSubmission::class, 'vendor_bills', $limit, null, fn ($submission) => $this->pushVendorBill($submission));
        $financeBillResult = $this->pushChangedSince(FinanceRecord::class, 'finance_bills', $limit, fn ($query) => $query->with('gateEntry'), fn ($record) => $this->pushFinanceBill($record));
        $receiveResult = $this->pushChangedSince(GrnRecord::class, 'purchase_receives', $limit, fn ($query) => $query->with('gateEntry'), fn ($record) => $this->pushPurchaseReceive($record));

        return [
            'items' => $itemResult['count'],
            'vendors' => $vendorResult['count'],
            'purchase_orders' => $poResult['count'],
            'bills' => $vendorBillResult['count'] + $financeBillResult['count'],
            'purchase_receives' => $receiveResult['count'],
            'failed' => $vendorResult['failed'] + $itemResult['failed'] + $poResult['failed']
                + $vendorBillResult['failed'] + $financeBillResult['failed'] + $receiveResult['failed'],
            'skipped' => false,
        ];
    }

    /**
     * Pushes only rows changed since this entity's last run — each cron cycle used to
     * re-push every Vendor/Item/PO/Bill/GRN in the database regardless of whether
     * anything changed, which burned through Zoho's daily API call cap (error code 45)
     * within a couple of 30-minute cycles and made every push fail afterward. The
     * checkpoint (like the one already used for pulling POs) bounds each run to real
     * deltas; $limit caps a single run's cost even when the delta or the initial
     * backfill is large, spreading it across multiple scheduled runs instead of
     * spending the whole day's quota in one shot.
     *
     * Cursor is (updated_at, id), not updated_at alone — seeded/bulk-imported rows
     * routinely share the same updated_at down to the second, and a plain "> checkpoint"
     * comparison silently strands every row tied with the last one in a batch forever
     * (confirmed locally: a 4-row table with 2 tied timestamps left 2 rows unsynced
     * indefinitely under a naive timestamp-only cursor).
     *
     * @return array{count: int, failed: int}
     */
    private function pushChangedSince(string $modelClass, string $checkpointKey, int $limit, ?\Closure $queryModifier, callable $pusher): array
    {
        $cacheKey = "zoho_inventory_push_checkpoint:{$checkpointKey}";
        $since = Cache::get($cacheKey);

        $count = 0;
        $failed = 0;

        $query = $modelClass::query();

        if ($queryModifier) {
            $queryModifier($query);
        }

        if ($since) {
            $query->where(function ($q) use ($since) {
                $q->where('updated_at', '>', $since['at'])
                    ->orWhere(function ($q2) use ($since) {
                        $q2->where('updated_at', '=', $since['at'])->where('id', '>', $since['id']);
                    });
            });
        }

        $records = $query->orderBy('updated_at')->orderBy('id')->limit($limit)->get();

        $records->each(function ($record) use ($pusher, &$count, &$failed) {
            $pusher($record) ? $count++ : $failed++;
        });

        // Only advance past this batch if everything in it actually succeeded — e.g.
        // during a rate-limit outage every push in the batch fails, and advancing
        // anyway would permanently mark those rows "handled" without ever having
        // synced them. Matches the same all-or-nothing convention already used by
        // the PO pull-side checkpoint below (only persists last_modified when
        // failed === 0), so one non-transient bad row can still stall an entity's
        // checkpoint — an accepted, pre-existing tradeoff, not a new one.
        if ($records->isNotEmpty() && $failed === 0) {
            $last = $records->last();
            Cache::forever($cacheKey, ['at' => $last->updated_at->toDateTimeString(), 'id' => $last->id]);
        }

        return ['count' => $count, 'failed' => $failed];
    }

    public function pushVendorContact(VendorMaster $vendor): bool
    {
        if (! $this->isActive() || trim($vendor->vendor_name) === '') {
            return false;
        }

        $email = trim((string) $vendor->contact_email);
        $phone = trim((string) $vendor->contact_phone);

        $payload = array_filter([
            'contact_name' => $vendor->vendor_name,
            'company_name' => $vendor->vendor_name,
            'contact_type' => 'vendor',
            'website' => $vendor->website,
            'gst_no' => $vendor->gst_number,
        ], fn ($value) => $value !== null && $value !== '');

        if ($email !== '' || $phone !== '') {
            $payload['contact_persons'] = [array_filter([
                'first_name' => $vendor->vendor_name,
                'email' => $email ?: null,
                'phone' => $phone ?: null,
                'is_primary_contact' => true,
            ], fn ($value) => $value !== null && $value !== '')];
        }

        return $this->upsertContact($vendor->vendor_name, $payload);
    }

    public function pushItem(SkuMaster $sku): bool
    {
        if (! $this->isActive() || trim($sku->sku) === '') {
            return false;
        }

        $payload = array_filter([
            'name' => $sku->sku,
            'sku' => $sku->sku,
            'item_type' => 'inventory',
            'product_type' => 'goods',
            'unit' => $sku->unit ?: 'pcs',
            'rate' => $sku->unit_price !== null ? (float) $sku->unit_price : 0,
            'purchase_rate' => $sku->unit_price !== null ? (float) $sku->unit_price : 0,
            'description' => $sku->category,
        ], fn ($value) => $value !== null && $value !== '');

        return $this->upsertItem($sku->sku, $payload);
    }

    /**
     * @return array{success: bool, action: string, status: ?int, message: string, zoho_id: ?string}
     */
    public function pushPurchaseOrder(PurchaseOrder $po): array
    {
        if (! $this->isActive() || ! config('services.zoho.inventory.write_enabled', true)) {
            return ['success' => false, 'action' => 'disabled', 'status' => null, 'message' => 'Zoho Inventory is not active.', 'zoho_id' => null];
        }

        if (trim((string) $po->po_number) === '' || trim((string) $po->vendor_name) === '') {
            return ['success' => false, 'action' => 'skipped', 'status' => null, 'message' => 'PO number or vendor name missing.', 'zoho_id' => null];
        }

        $vendor = $this->findOrCreateContact($po->vendor_name);
        if (! $vendor) {
            return ['success' => false, 'action' => 'failed', 'status' => null, 'message' => $this->lastError ?: 'Vendor contact lookup failed.', 'zoho_id' => null];
        }

        $lineItems = [];
        foreach ($po->lines as $line) {
            $item = $this->findOrCreateItem((string) $line->product, (float) $line->list_price);
            if (! $item) {
                continue;
            }

            $lineItems[] = [
                'item_id' => $item['item_id'],
                'rate' => (float) $line->list_price,
                'quantity' => (float) $line->quantity,
            ];
        }

        if (empty($lineItems)) {
            return ['success' => false, 'action' => 'skipped', 'status' => null, 'message' => 'No line items to sync.', 'zoho_id' => null];
        }

        $payload = array_filter([
            'vendor_id' => $vendor['contact_id'],
            'reference_number' => $po->po_number,
            'date' => optional($po->po_date)->format('Y-m-d'),
            'delivery_date' => optional($po->due_date)->format('Y-m-d'),
            'notes' => $po->description,
        ], fn ($value) => $value !== null && $value !== '');
        $payload['line_items'] = $lineItems;

        $existing = $this->findPurchaseOrderByReference($po->po_number);

        $response = $existing
            ? $this->inventoryRequest()->put($this->invUrl("/purchaseorders/{$existing['purchaseorder_id']}"), $payload)
            : $this->inventoryRequest()->post($this->invUrl('/purchaseorders'), $payload);

        $success = $response->successful() && (int) $response->json('code') === 0;

        return [
            'success' => $success,
            'action' => $existing ? 'updated' : 'created',
            'status' => $response->status(),
            'message' => $success ? 'ok' : (string) ($response->json('message') ?: $response->body()),
            'zoho_id' => $response->json('purchaseorder.purchaseorder_id'),
        ];
    }

    public function pushVendorBill(VendorSubmission $submission): bool
    {
        if (! $this->isActive() || trim((string) $submission->invoice_number) === '') {
            return false;
        }

        $vendor = $this->findOrCreateContact($submission->vendor_name);
        if (! $vendor) {
            return false;
        }

        $item = $this->findOrCreateItem($submission->material ?: 'Tan90 Vendor Bill Item');
        if (! $item) {
            return false;
        }

        $payload = array_filter([
            'vendor_id' => $vendor['contact_id'],
            'bill_number' => $submission->invoice_number,
            'reference_number' => $submission->po_number,
            'date' => now()->format('Y-m-d'),
        ], fn ($value) => $value !== null && $value !== '');
        $payload['line_items'] = [[
            'item_id' => $item['item_id'],
            'quantity' => max(1, (float) $submission->invoice_qty),
            'rate' => 0,
        ]];

        return $this->upsertBill($submission->invoice_number, $payload);
    }

    public function pushFinanceBill(FinanceRecord $record): bool
    {
        if (! $this->isActive() || trim((string) $record->invoice_number) === '') {
            return false;
        }

        $vendor = $this->findOrCreateContact($record->vendor_name);
        if (! $vendor) {
            return false;
        }

        $itemName = $record->gateEntry?->material ?: 'Tan90 Finance Item';
        $item = $this->findOrCreateItem($itemName, (float) $record->rate_per_unit);
        if (! $item) {
            return false;
        }

        $payload = array_filter([
            'vendor_id' => $vendor['contact_id'],
            'bill_number' => $record->invoice_number,
            'reference_number' => $record->gateEntry?->po_number,
            'date' => now()->format('Y-m-d'),
        ], fn ($value) => $value !== null && $value !== '');
        $payload['line_items'] = [[
            'item_id' => $item['item_id'],
            'quantity' => max(1, (float) ($record->gateEntry?->invoice_qty ?: 1)),
            'rate' => max(0, (float) $record->rate_per_unit),
        ]];

        return $this->upsertBill($record->invoice_number, $payload);
    }

    /** One Purchase Receive per PO, looked up and updated rather than re-created on every GRN re-save (posting, corrections, etc.). */
    public function pushPurchaseReceive(GrnRecord $record): bool
    {
        if (! $this->isActive() || ! config('services.zoho.inventory.write_enabled', true)) {
            return false;
        }

        $poNumber = $record->gateEntry?->po_number;
        if (! $poNumber) {
            return false;
        }

        $po = $this->findPurchaseOrderByReference($poNumber);
        if (! $po || empty($po['line_items'][0])) {
            return false;
        }

        $primaryLine = $po['line_items'][0];
        $itemId = $primaryLine['item_id'] ?? null;
        $poLineItemId = $primaryLine['line_item_id'] ?? $primaryLine['purchaseorder_item_id'] ?? null;

        if (! $itemId || ! $poLineItemId) {
            return false;
        }

        $payload = array_filter([
            'vendor_id' => $po['vendor_id'] ?? null,
            'purchaseorder_id' => $po['purchaseorder_id'],
            'date' => now()->format('Y-m-d'),
        ], fn ($value) => $value !== null);
        $payload['line_items'] = [[
            'po_line_item_id' => $poLineItemId,
            'item_id' => $itemId,
            'quantity_received' => max(0, (float) $record->accepted_qty),
        ]];

        $existing = $this->findPurchaseReceiveForPo((string) $po['purchaseorder_id']);

        $response = $existing
            ? $this->inventoryRequest()->put($this->invUrl("/purchasereceives/{$existing['purchasereceive_id']}"), $payload)
            : $this->inventoryRequest()->post($this->invUrl('/purchasereceives'), $payload);

        return $response->successful() && (int) $response->json('code') === 0;
    }

    /**
     * @return array{vendors: int, items: int, failed: int}
     */
    public function syncMasterData(int $limit = 200): array
    {
        if (! $this->isActive()) {
            return ['vendors' => 0, 'items' => 0, 'failed' => 1];
        }

        return [
            'vendors' => $this->syncVendors($limit),
            'items' => $this->syncItems($limit),
            'failed' => 0,
        ];
    }

    private function syncVendors(int $limit): int
    {
        $response = $this->inventoryRequest()->get($this->invUrl('/contacts', [
            'contact_type' => 'vendor',
            'per_page' => min(max($limit, 1), 200),
            'sort_column' => 'last_modified_time',
            'sort_order' => 'D',
        ]));

        if (! $response->successful()) {
            return 0;
        }

        $count = 0;

        foreach ($response->json('contacts', []) as $record) {
            $name = trim((string) ($record['contact_name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $existing = VendorMaster::where('vendor_name', $name)->first();
            $primaryContact = $record['contact_persons'][0] ?? [];

            // withoutEvents — this data just came FROM Inventory, so writing
            // it back must not re-trigger the observer's outbound push.
            VendorMaster::withoutEvents(fn () => VendorMaster::updateOrCreate(
                ['vendor_name' => $name],
                [
                    'gst_number' => ($record['gst_no'] ?? null) ?: ($existing?->gst_number ?: 'ZOHO-N/A'),
                    'contact_phone' => (string) (($primaryContact['phone'] ?? $existing?->contact_phone) ?: 'N/A'),
                    'contact_email' => $primaryContact['email'] ?? $existing?->contact_email,
                    'category' => $existing?->category ?: 'Zoho Vendor',
                    'active' => ($record['status'] ?? 'active') !== 'inactive',
                    'website' => $record['website'] ?? $existing?->website,
                ],
            ));

            $count++;
        }

        return $count;
    }

    private function syncItems(int $limit): int
    {
        $response = $this->inventoryRequest()->get($this->invUrl('/items', [
            'per_page' => min(max($limit, 1), 200),
            'sort_column' => 'last_modified_time',
            'sort_order' => 'D',
        ]));

        if (! $response->successful()) {
            return 0;
        }

        $count = 0;

        foreach ($response->json('items', []) as $record) {
            $sku = trim((string) ($record['sku'] ?? $record['name'] ?? ''));

            if ($sku === '') {
                continue;
            }

            $existing = SkuMaster::where('sku', $sku)->first();

            SkuMaster::withoutEvents(fn () => SkuMaster::updateOrCreate(
                ['sku' => $sku],
                [
                    'category' => $existing?->category ?: ($record['category_name'] ?? 'Zoho Item'),
                    'unit' => $record['unit'] ?? $existing?->unit,
                    'active' => ($record['status'] ?? 'active') !== 'inactive',
                    'product_code' => $record['sku'] ?? $existing?->product_code,
                    'unit_price' => $record['rate'] ?? $existing?->unit_price,
                    'quantity_in_stock' => $record['stock_on_hand'] ?? $existing?->quantity_in_stock,
                ],
            ));

            $count++;
        }

        return $count;
    }

    private function syncPurchaseOrderData(?array $invPo): ?PurchaseOrder
    {
        if (! $invPo || empty($invPo['reference_number'])) {
            return null;
        }

        $po = PurchaseOrder::withoutEvents(fn () => PurchaseOrder::updateOrCreate(
            ['po_number' => $invPo['reference_number']],
            [
                'subject' => $invPo['reference_number'],
                'vendor_name' => $invPo['vendor_name'] ?? 'Zoho Vendor',
                'po_date' => $invPo['date'] ?? null,
                'due_date' => $invPo['delivery_date'] ?? null,
                'status' => 'Approved',
                'description' => 'Synced from Zoho Inventory Purchase Orders.',
            ],
        ));

        PurchaseOrderLine::withoutEvents(function () use ($po, $invPo) {
            $po->lines()->delete();

            foreach ($invPo['line_items'] ?? [] as $line) {
                $po->lines()->create([
                    'product' => $line['name'] ?? ($line['item_name'] ?? 'Zoho Item'),
                    'quantity' => max(1, (float) ($line['quantity'] ?? 1)),
                    'list_price' => max(0, (float) ($line['rate'] ?? 0)),
                ]);
            }
        });

        return $po->load('lines');
    }

    /**
     * @return array{synced: int, skipped: int, failed: int, last_modified: ?string}
     */
    public function syncRecentlyModifiedPurchaseOrders(?string $since = null, int $limit = 100): array
    {
        if (! $this->isActive()) {
            return ['synced' => 0, 'skipped' => 0, 'failed' => 1, 'last_modified' => $since];
        }

        $response = $this->inventoryRequest()->get($this->invUrl('/purchaseorders', [
            'per_page' => min(max($limit, 1), 200),
            'sort_column' => 'last_modified_time',
            'sort_order' => 'D',
        ]));

        if (! $response->successful()) {
            return ['synced' => 0, 'skipped' => 0, 'failed' => 1, 'last_modified' => $since];
        }

        $synced = 0;
        $skipped = 0;
        $failed = 0;
        $latest = $since;

        foreach ($response->json('purchaseorders', []) as $record) {
            $modified = $record['last_modified_time'] ?? null;

            if ($since && $modified && strcmp($modified, $since) <= 0) {
                $skipped++;
                continue;
            }

            // The list endpoint doesn't include line_items — fetch the full record.
            $full = $this->inventoryRequest()->get($this->invUrl("/purchaseorders/{$record['purchaseorder_id']}"));
            $po = $full->successful() ? $this->syncPurchaseOrderData($full->json('purchaseorder')) : null;
            $po ? $synced++ : $failed++;

            if ($modified && (! $latest || strcmp($modified, $latest) > 0)) {
                $latest = $modified;
            }
        }

        return ['synced' => $synced, 'skipped' => $skipped, 'failed' => $failed, 'last_modified' => $latest];
    }

    private function findContactByName(string $name): ?array
    {
        $response = $this->inventoryRequest()->get($this->invUrl('/contacts', ['contact_name' => $name]));

        if (! $response->successful()) {
            return null;
        }

        foreach ($response->json('contacts', []) as $contact) {
            if (strcasecmp((string) ($contact['contact_name'] ?? ''), $name) === 0) {
                return $contact;
            }
        }

        return null;
    }

    private function upsertContact(string $name, array $payload): bool
    {
        if (! config('services.zoho.inventory.write_enabled', true)) {
            return false;
        }

        $existing = $this->findContactByName($name);

        $response = $existing
            ? $this->inventoryRequest()->put($this->invUrl("/contacts/{$existing['contact_id']}"), $payload)
            : $this->inventoryRequest()->post($this->invUrl('/contacts'), $payload);

        return $response->successful() && (int) $response->json('code') === 0;
    }

    /**
     * @return array{contact_id: string}|null
     */
    private function findOrCreateContact(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $existing = $this->findContactByName($name);
        if ($existing) {
            return $existing;
        }

        $response = $this->inventoryRequest()->post($this->invUrl('/contacts'), [
            'contact_name' => $name,
            'company_name' => $name,
            'contact_type' => 'vendor',
        ]);

        if (! $response->successful() || (int) $response->json('code') !== 0) {
            $this->lastError = "Zoho Inventory vendor lookup/create failed for {$name}: ".$response->body();

            return null;
        }

        return $response->json('contact');
    }

    private function findItemBySku(string $sku): ?array
    {
        $response = $this->inventoryRequest()->get($this->invUrl('/items', ['sku' => $sku]));

        if (! $response->successful()) {
            return null;
        }

        foreach ($response->json('items', []) as $item) {
            if (strcasecmp((string) ($item['sku'] ?? ''), $sku) === 0) {
                return $item;
            }
        }

        return null;
    }

    private function upsertItem(string $sku, array $payload): bool
    {
        if (! config('services.zoho.inventory.write_enabled', true)) {
            return false;
        }

        $existing = $this->findItemBySku($sku);

        $response = $existing
            ? $this->inventoryRequest()->put($this->invUrl("/items/{$existing['item_id']}"), $payload)
            : $this->inventoryRequest()->post($this->invUrl('/items'), $payload);

        return $response->successful() && (int) $response->json('code') === 0;
    }

    /**
     * @return array{item_id: string}|null
     */
    private function findOrCreateItem(string $sku, ?float $rate = null): ?array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $existing = $this->findItemBySku($sku);
        if ($existing) {
            return $existing;
        }

        $response = $this->inventoryRequest()->post($this->invUrl('/items'), array_filter([
            'name' => $sku,
            'sku' => $sku,
            'item_type' => 'inventory',
            'product_type' => 'goods',
            'unit' => 'pcs',
            'rate' => $rate ?? 0,
        ], fn ($value) => $value !== null));

        if (! $response->successful() || (int) $response->json('code') !== 0) {
            $this->lastError = "Zoho Inventory item lookup/create failed for {$sku}: ".$response->body();

            return null;
        }

        return $response->json('item');
    }

    private function findPurchaseOrderByReference(string $reference): ?array
    {
        $response = $this->inventoryRequest()->get($this->invUrl('/purchaseorders', ['reference_number' => $reference]));

        if (! $response->successful()) {
            return null;
        }

        foreach ($response->json('purchaseorders', []) as $po) {
            if (strcasecmp((string) ($po['reference_number'] ?? ''), $reference) === 0) {
                // The list endpoint doesn't include line_items — fetch the full record.
                $full = $this->inventoryRequest()->get($this->invUrl("/purchaseorders/{$po['purchaseorder_id']}"));

                return $full->successful() ? $full->json('purchaseorder') : $po;
            }
        }

        return null;
    }

    private function findBillByNumber(string $billNumber): ?array
    {
        $response = $this->inventoryRequest()->get($this->invUrl('/bills', ['bill_number' => $billNumber]));

        if (! $response->successful()) {
            return null;
        }

        foreach ($response->json('bills', []) as $bill) {
            if (strcasecmp((string) ($bill['bill_number'] ?? ''), $billNumber) === 0) {
                return $bill;
            }
        }

        return null;
    }

    private function upsertBill(string $billNumber, array $payload): bool
    {
        if (! config('services.zoho.inventory.write_enabled', true)) {
            return false;
        }

        $existing = $this->findBillByNumber($billNumber);

        $response = $existing
            ? $this->inventoryRequest()->put($this->invUrl("/bills/{$existing['bill_id']}"), $payload)
            : $this->inventoryRequest()->post($this->invUrl('/bills'), $payload);

        return $response->successful() && (int) $response->json('code') === 0;
    }

    private function findPurchaseReceiveForPo(string $purchaseOrderId): ?array
    {
        $response = $this->inventoryRequest()->get($this->invUrl('/purchasereceives', ['purchaseorder_id' => $purchaseOrderId]));

        if (! $response->successful()) {
            return null;
        }

        return $response->json('purchasereceives.0');
    }
}
