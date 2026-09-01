<?php

namespace App\Services;

use App\Services\Zoho\ZohoApiGate;
use App\Services\Zoho\ZohoResult;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use App\Models\FinanceRecord;
use App\Models\GrnRecord;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SkuMaster;
use App\Models\VendorMaster;
use App\Models\VendorSubmission;
use App\Models\ZohoEntityLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    public function __construct(private readonly ZohoApiGate $apiGate) {}

    public function isActive(): bool
    {
        return (bool) config('services.zoho.inventory.organization_id')
            && (bool) config('services.zoho.inventory.refresh_token');
    }

    /**
     * Superseded by App\Services\Zoho\ZohoApiGate, which is now the sole authority on
     * blocking — it gates every HTTP call directly (see inventoryRequest()) rather than
     * this class independently deciding to pause based on string-matching a response
     * body. Keeping both created a real bug: this class's old signature check matched
     * on the gate's own "blocked locally" synthetic response (it deliberately echoes
     * Zoho's code:45 shape), so every local block re-armed *this* class's separate
     * 180-minute cooldown — confirmed live, stuck open a full hour after the gate's own
     * breaker had already closed. One authority, not two disagreeing ones.
     */
    private function accessToken(): ?string
    {
        if (! $this->isActive()) {
            return null;
        }

        // Cache refresh failures briefly. Cache::remember() does not retain null,
        // which previously caused every data request to hammer the token endpoint
        // again when Zoho authentication was unavailable.
        if (Cache::get('zoho_inventory_access_token_failed')) {
            return null;
        }

        return Cache::remember('zoho_inventory_access_token', 3300, function () {
            $accountsBase = config('services.zoho.accounts_base_url');
            $response = Http::asForm()
                ->connectTimeout((int) config('services.zoho.inventory.connect_timeout', 5))
                ->timeout((int) config('services.zoho.inventory.timeout', 15))
                ->post("{$accountsBase}/oauth/v2/token", [
                'refresh_token' => config('services.zoho.inventory.refresh_token'),
                'client_id' => config('services.zoho.inventory.client_id'),
                'client_secret' => config('services.zoho.inventory.client_secret'),
                'grant_type' => 'refresh_token',
            ]);

            if (! $response->successful() || $response->json('error')) {
                Cache::put('zoho_inventory_access_token_failed', true, now()->addMinutes(10));

                return null;
            }

            Cache::forget('zoho_inventory_access_token_failed');

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

        return Http::withHeaders(['Authorization' => "Zoho-oauthtoken {$token}"])
            ->connectTimeout((int) config('services.zoho.inventory.connect_timeout', 5))
            ->timeout((int) config('services.zoho.inventory.timeout', 15))
            ->withMiddleware(function (callable $handler): callable {
                return function ($request, array $options) use ($handler) {
                    if ($reason = $this->apiGate->acquire()) {
                        $body = json_encode([
                            'code' => 45,
                            'message' => $reason,
                            'blocked_locally' => true,
                        ], JSON_THROW_ON_ERROR);

                        return Create::promiseFor(new Psr7Response(
                            429,
                            ['Content-Type' => 'application/json', 'X-Tan90-Zoho-Blocked' => '1'],
                            $body,
                        ));
                    }

                    return $handler($request, $options)->then(function ($response) {
                        $raw = (string) $response->getBody();
                        $body = json_decode($raw, true);
                        $body = is_array($body) ? $body : [];
                        $outcome = $this->apiGate->classify($response->getStatusCode(), $body, $raw);

                        $this->apiGate->record(new ZohoResult(
                            $outcome,
                            $response->getStatusCode(),
                            $body,
                            (string) ($body['message'] ?? $raw),
                        ));

                        return $response;
                    });
                };
            });
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

        $items = 0;
        $vendors = 0;
        $purchaseOrders = 0;
        $bills = 0;
        $purchaseReceives = 0;
        $failed = 0;

        $entities = [
            ['vendors', VendorMaster::class, null, fn ($v) => $this->pushVendorContact($v)],
            ['items', SkuMaster::class, null, fn ($v) => $this->pushItem($v)],
            ['purchase_orders', PurchaseOrder::class, fn ($q) => $q->with('lines'), fn ($v) => $this->pushPurchaseOrder($v)['success']],
            ['vendor_bills', VendorSubmission::class, null, fn ($v) => $this->pushVendorBill($v)],
            ['finance_bills', FinanceRecord::class, fn ($q) => $q->with('gateEntry'), fn ($v) => $this->pushFinanceBill($v)],
            ['purchase_receives', GrnRecord::class, fn ($q) => $q->with('gateEntry'), fn ($v) => $this->pushPurchaseReceive($v)],
        ];

        foreach ($entities as [$key, $modelClass, $queryModifier, $pusher]) {
            $result = $this->pushChangedSince($modelClass, $key, $limit, $queryModifier, $pusher);
            $failed += $result['failed'];

            match ($key) {
                'vendors' => $vendors += $result['count'],
                'items' => $items += $result['count'],
                'purchase_orders' => $purchaseOrders += $result['count'],
                'vendor_bills', 'finance_bills' => $bills += $result['count'],
                'purchase_receives' => $purchaseReceives += $result['count'],
            };

            // Stop entirely once rate-limited — every remaining entity would fail
            // for the same reason, and each attempt just burns more of the quota
            // this run is trying to let recover.
            if ($result['rate_limited']) {
                break;
            }
        }

        return [
            'items' => $items,
            'vendors' => $vendors,
            'purchase_orders' => $purchaseOrders,
            'bills' => $bills,
            'purchase_receives' => $purchaseReceives,
            'failed' => $failed,
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
     * @return array{count: int, failed: int, rate_limited: bool}
     */
    private function pushChangedSince(string $modelClass, string $checkpointKey, int $limit, ?\Closure $queryModifier, callable $pusher): array
    {
        $cacheKey = "zoho_inventory_push_checkpoint:{$checkpointKey}";
        $since = Cache::get($cacheKey);

        $count = 0;
        $failed = 0;
        $rateLimited = false;

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
        $lastAttempted = null;
        $maxFailures = (int) config('services.zoho.inventory.max_record_failures', 3);

        foreach ($records as $record) {
            if ($rateLimited) {
                break;
            }

            // A record that has burned through its failure budget keeps rejecting for
            // the same content reason every time — Zoho isn't going to change its mind
            // between cron runs. Skipping it here is what actually stops one bad GST
            // number or a stale duplicate-item conflict from consuming API calls on an
            // outcome that's already known, forever.
            $link = ZohoEntityLink::for($record, $checkpointKey);
            if ($link->isQuarantined()) {
                continue;
            }

            $this->lastError = null;
            $lastAttempted = $record;

            if ($pusher($record)) {
                $count++;

                if ($link->exists && $link->failure_count > 0) {
                    $link->forceFill(['failure_count' => 0, 'last_error' => null, 'quarantined_at' => null])->save();
                }

                continue;
            }

            $failed++;
            // The scheduler discards each command's stdout, so this is the only place
            // a failure's actual cause (rate limit, validation, network) is recorded —
            // without it, "exit code 1" in the log is indistinguishable from a real bug.
            // error, not warning — production's log level (confirmed 0 WARNING lines
            // across 36k+ log entries, vs 1,645 ERROR lines that came through fine)
            // filters warning-level out entirely, which would make this addition a
            // no-op there.
            Log::error('Zoho Inventory push failed', [
                'entity' => $checkpointKey,
                'model_id' => $record->id,
                'error' => $this->lastError ?: 'no error detail captured',
            ]);

            // The gate's own synthetic block response is the only reliable "this call
            // never left the process" signal — it's our own marker, not Zoho's wording,
            // so it can't drift out of sync the way matching Zoho's error text did.
            if (str_contains((string) $this->lastError, '"blocked_locally":true')) {
                $rateLimited = true;

                continue;
            }

            // Not a blocked call, so Zoho actually answered and rejected this record's
            // content — a data problem, not a quota problem. Count it toward
            // quarantine so it stops being retried once it's clearly never going to
            // succeed as-is.
            $link->markPermanentFailure($this->lastError ?: 'unknown error', $maxFailures);
        }

        // Only advance past what was actually attempted, and only if every attempted
        // row succeeded — e.g. during a rate-limit outage every push fails, and
        // advancing anyway would permanently mark those rows "handled" without ever
        // having synced them. Matches the same all-or-nothing convention already used
        // by the PO pull-side checkpoint below (only persists last_modified when
        // failed === 0), so one non-transient bad row can still stall an entity's
        // checkpoint — an accepted, pre-existing tradeoff, not a new one.
        if ($lastAttempted && $failed === 0) {
            Cache::forever($cacheKey, ['at' => $lastAttempted->updated_at->toDateTimeString(), 'id' => $lastAttempted->id]);
        }

        return ['count' => $count, 'failed' => $failed, 'rate_limited' => $rateLimited];
    }

    public function pushVendorContact(VendorMaster $vendor): bool
    {
        if (! $this->isActive() || trim($vendor->vendor_name) === '') {
            return false;
        }

        $email = trim((string) $vendor->contact_email);
        $phone = trim((string) $vendor->contact_phone);
        // Zoho rejects anything that isn't a real 15-char GSTIN with code 2
        // ("Invalid value passed for gst_no") — placeholders like 'ZOHO-N/A'
        // (the fallback this app itself writes when a vendor's GST is unknown,
        // see syncVendors()) fail that check and permanently block the push.
        // GST is optional in Zoho, so just omit it rather than block the vendor.
        $gstNumber = strtoupper(trim((string) $vendor->gst_number));
        $validGst = (bool) preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z]{1}[A-Z\d]{1}Z[A-Z\d]{1}$/', $gstNumber);

        $payload = array_filter([
            'contact_name' => $vendor->vendor_name,
            'company_name' => $vendor->vendor_name,
            'contact_type' => 'vendor',
            'website' => $vendor->website,
            'gst_no' => $validGst ? $gstNumber : null,
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

        if ($existing === false) {
            return ['success' => false, 'action' => 'failed', 'status' => null, 'message' => "Zoho Inventory PO lookup failed for {$po->po_number} — not attempting an upsert blind, to avoid creating a duplicate.", 'zoho_id' => null];
        }

        $response = $existing
            ? $this->inventoryRequest()->put($this->invUrl("/purchaseorders/{$existing['purchaseorder_id']}"), $payload)
            : $this->inventoryRequest()->post($this->invUrl('/purchaseorders'), $payload);

        $success = $response->successful() && (int) $response->json('code') === 0;
        $message = $success ? 'ok' : (string) ($response->json('message') ?: $response->body());

        if (! $success) {
            $this->lastError = "Zoho Inventory PO upsert failed for {$po->po_number}: {$message}";
        }

        return [
            'success' => $success,
            'action' => $existing ? 'updated' : 'created',
            'status' => $response->status(),
            'message' => $message,
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
            $this->lastError = "Zoho Inventory purchase receive for GRN #{$record->id}: no PO number on its gate entry.";

            return false;
        }

        $po = $this->findPurchaseOrderByReference($poNumber);
        if ($po === false) {
            $this->lastError = "Zoho Inventory purchase receive for GRN #{$record->id}: PO lookup for {$poNumber} failed — not attempting blind, to avoid creating a duplicate.";

            return false;
        }
        if (! $po || empty($po['line_items'][0])) {
            $this->lastError = "Zoho Inventory purchase receive for GRN #{$record->id}: PO {$poNumber} not found in Inventory or has no line items.";

            return false;
        }

        $primaryLine = $po['line_items'][0];
        $itemId = $primaryLine['item_id'] ?? null;
        $poLineItemId = $primaryLine['line_item_id'] ?? $primaryLine['purchaseorder_item_id'] ?? null;

        if (! $itemId || ! $poLineItemId) {
            $this->lastError = "Zoho Inventory purchase receive for GRN #{$record->id}: PO {$poNumber} line item missing item_id/line_item_id.";

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

        if ($existing === false) {
            $this->lastError = "Zoho Inventory purchase receive lookup failed for GRN #{$record->id} (PO {$poNumber}) — not attempting an upsert blind, to avoid creating a duplicate.";

            return false;
        }

        $response = $existing
            ? $this->inventoryRequest()->put($this->invUrl("/purchasereceives/{$existing['purchasereceive_id']}"), $payload)
            : $this->inventoryRequest()->post($this->invUrl('/purchasereceives'), $payload);

        if ($response->successful() && (int) $response->json('code') === 0) {
            return true;
        }

        $this->lastError = "Zoho Inventory purchase receive upsert failed for GRN #{$record->id} (PO {$poNumber}): ".$response->body();

        return false;
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
            return ['synced' => 0, 'skipped' => 0, 'failed' => 0, 'last_modified' => $since];
        }

        $response = $this->inventoryRequest()->get($this->invUrl('/purchaseorders', [
            'per_page' => min(max($limit, 1), 200),
            'sort_column' => 'last_modified_time',
            'sort_order' => 'D',
        ]));

        if (! $response->successful()) {
            // A locally-blocked call (gate closed the door before this ever reached
            // the wire) isn't a real failure — nothing to log or count.
            $blocked = str_contains($response->body(), '"blocked_locally":true');

            return ['synced' => 0, 'skipped' => 0, 'failed' => $blocked ? 0 : 1, 'last_modified' => $since];
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

            if (! $full->successful() && str_contains($full->body(), '"blocked_locally":true')) {
                break;
            }

            $po = $full->successful() ? $this->syncPurchaseOrderData($full->json('purchaseorder')) : null;
            $po ? $synced++ : $failed++;

            if ($modified && (! $latest || strcmp($modified, $latest) > 0)) {
                $latest = $modified;
            }
        }

        return ['synced' => $synced, 'skipped' => $skipped, 'failed' => $failed, 'last_modified' => $latest];
    }

    /**
     * Returns the matching contact, null when the lookup succeeded and genuinely found
     * nothing, or false when the lookup call itself failed. That distinction matters:
     * every caller used to treat "couldn't check" the same as "doesn't exist" and went
     * on to create a new record — which is exactly how a transient failure on this GET
     * turned into a real "Item already exists" / duplicate-contact rejection on the
     * POST right after it.
     */
    private function findContactByName(string $name): array|false|null
    {
        $response = $this->inventoryRequest()->get($this->invUrl('/contacts', ['contact_name' => $name]));

        if (! $response->successful()) {
            return false;
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

        if ($existing === false) {
            $this->lastError = "Zoho Inventory contact lookup failed for {$name} — not attempting an upsert blind, to avoid creating a duplicate.";

            return false;
        }

        $response = $existing
            ? $this->inventoryRequest()->put($this->invUrl("/contacts/{$existing['contact_id']}"), $payload)
            : $this->inventoryRequest()->post($this->invUrl('/contacts'), $payload);

        if ($response->successful() && (int) $response->json('code') === 0) {
            return true;
        }

        $this->lastError = "Zoho Inventory contact upsert failed for {$name}: ".$response->body();

        return false;
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
        if ($existing === false) {
            $this->lastError = "Zoho Inventory vendor lookup failed for {$name} — not attempting create to avoid a duplicate.";

            return null;
        }
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

    /** See findContactByName() — same false-on-lookup-failure vs null-on-genuinely-not-found distinction. */
    private function findItemBySku(string $sku): array|false|null
    {
        $response = $this->inventoryRequest()->get($this->invUrl('/items', ['sku' => $sku]));

        if (! $response->successful()) {
            return false;
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

        if ($existing === false) {
            $this->lastError = "Zoho Inventory item lookup failed for {$sku} — not attempting an upsert blind, to avoid creating a duplicate.";

            return false;
        }

        $response = $existing
            ? $this->inventoryRequest()->put($this->invUrl("/items/{$existing['item_id']}"), $payload)
            : $this->inventoryRequest()->post($this->invUrl('/items'), $payload);

        if ($response->successful() && (int) $response->json('code') === 0) {
            return true;
        }

        $this->lastError = "Zoho Inventory item upsert failed for {$sku}: ".$response->body();

        return false;
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
        if ($existing === false) {
            $this->lastError = "Zoho Inventory item lookup failed for {$sku} — not attempting create to avoid a duplicate.";

            return null;
        }
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

    /** See findContactByName() — same false-on-lookup-failure vs null-on-genuinely-not-found distinction. */
    private function findPurchaseOrderByReference(string $reference): array|false|null
    {
        $response = $this->inventoryRequest()->get($this->invUrl('/purchaseorders', ['reference_number' => $reference]));

        if (! $response->successful()) {
            return false;
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

    /** See findContactByName() — same false-on-lookup-failure vs null-on-genuinely-not-found distinction. */
    private function findBillByNumber(string $billNumber): array|false|null
    {
        $response = $this->inventoryRequest()->get($this->invUrl('/bills', ['bill_number' => $billNumber]));

        if (! $response->successful()) {
            return false;
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

        if ($existing === false) {
            $this->lastError = "Zoho Inventory bill lookup failed for {$billNumber} — not attempting an upsert blind, to avoid creating a duplicate.";

            return false;
        }

        $response = $existing
            ? $this->inventoryRequest()->put($this->invUrl("/bills/{$existing['bill_id']}"), $payload)
            : $this->inventoryRequest()->post($this->invUrl('/bills'), $payload);

        if ($response->successful() && (int) $response->json('code') === 0) {
            return true;
        }

        $this->lastError = "Zoho Inventory bill upsert failed for {$billNumber}: ".$response->body();

        return false;
    }

    /** See findContactByName() — same false-on-lookup-failure vs null-on-genuinely-not-found distinction. */
    private function findPurchaseReceiveForPo(string $purchaseOrderId): array|false|null
    {
        $response = $this->inventoryRequest()->get($this->invUrl('/purchasereceives', ['purchaseorder_id' => $purchaseOrderId]));

        if (! $response->successful()) {
            return false;
        }

        return $response->json('purchasereceives.0');
    }
}
