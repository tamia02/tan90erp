<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\FinanceRecord;
use App\Models\GateEntry;
use App\Models\GrnRecord;
use App\Models\Rfq;
use App\Models\QcResult;
use App\Models\SkuMaster;
use App\Models\Tan90\BomRecipeCosting\Bom;
use App\Models\Tan90\BomRecipeCosting\CostSheet;
use App\Models\Tan90\BomRecipeCosting\Recipe;
use App\Models\VendorSubmission;
use App\Models\VendorMaster;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// (ZohoInventoryService lives in the same namespace — no import needed.)

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
    private ?string $lastLookupError = null;

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

        $response = $this->zohoRequest()
            ->get("{$apiDomain}/crm/v3/{$moduleApiName}/search", ['criteria' => $criteria]);

        if ($response->status() === 204 || ! $response->successful()) {
            return null;
        }

        $record = $response->json('data.0');

        return $record ? $this->normalizePurchaseOrderRecord($record, $poNumber) : null;
    }

    private function findRawPurchaseOrder(string $poNumber): ?array
    {
        $apiDomain = $this->apiDomain();
        $moduleApiName = config('services.zoho.po_module_api_name', 'Purchase_Orders');

        if (! $apiDomain) {
            return null;
        }

        $poField = config('services.zoho.field_po', 'PO_Number');
        $criteria = "({$poField}:equals:{$poNumber})";

        $response = $this->zohoRequest()
            ->get("{$apiDomain}/crm/v3/{$moduleApiName}/search", ['criteria' => $criteria]);

        return $response->successful() ? $response->json('data.0') : null;
    }

    public function findPurchaseOrderById(string $recordId): ?array
    {
        $apiDomain = $this->apiDomain();
        $moduleApiName = config('services.zoho.po_module_api_name', 'Purchase_Orders');

        if (! $apiDomain) {
            return null;
        }

        $response = $this->zohoRequest()
            ->get("{$apiDomain}/crm/v3/{$moduleApiName}/{$recordId}");

        $record = $response->successful() ? $response->json('data.0') : null;

        return $record ? $this->normalizePurchaseOrderRecord($record) : null;
    }

    /**
     * @return array{poNumber: string, vendorName: string, subject: string, material: string, quantity: int, listPrice: float}|null
     */
    private function normalizePurchaseOrderRecord(array $record, ?string $fallbackPoNumber = null): ?array
    {
        if (! isset($record[config('services.zoho.field_product_details', 'Product_Details')]) && ! empty($record['id'])) {
            $detail = $this->findRawPurchaseOrderById((string) $record['id']);

            if ($detail) {
                $record = $detail;
            }
        }

        $vendorField = $record[config('services.zoho.field_vendor_name', 'Vendor_Name')] ?? null;
        $vendorName = is_array($vendorField) ? ($vendorField['name'] ?? '') : (string) ($vendorField ?? '');

        $poField = config('services.zoho.field_po', 'PO_Number');
        $lines = $record[config('services.zoho.field_product_details', 'Purchase_Items')]
            ?? $record['Product_Details']
            ?? [];
        $primaryLine = $lines[0] ?? null;
        $productField = is_array($primaryLine) ? ($primaryLine['Product_Name'] ?? $primaryLine['product'] ?? null) : null;
        $productName = is_array($productField) ? ($productField['name'] ?? '') : (string) ($productField ?? '');

        return [
            'poNumber' => (string) ($record[$poField] ?? $fallbackPoNumber ?? $record['id'] ?? ''),
            'vendorName' => $vendorName,
            'subject' => $record[config('services.zoho.field_subject', 'Subject')] ?? '',
            'material' => $productName,
            'quantity' => (int) ($primaryLine['Quantity'] ?? $primaryLine['quantity'] ?? 0),
            'listPrice' => (float) ($primaryLine['List_Price'] ?? $primaryLine['list_price'] ?? 0),
        ];
    }

    public function syncPurchaseOrder(string $poNumber): ?PurchaseOrder
    {
        $zohoPo = $this->findPurchaseOrder($poNumber);

        return $this->syncPurchaseOrderData($zohoPo);
    }

    public function syncPurchaseOrderById(string $recordId): ?PurchaseOrder
    {
        $zohoPo = $this->findPurchaseOrderById($recordId);

        return $this->syncPurchaseOrderData($zohoPo);
    }

    /**
     * @return array{synced: int, skipped: int, failed: int, last_modified: ?string}
     */
    public function syncRecentlyModifiedPurchaseOrders(?string $since = null, int $limit = 100): array
    {
        $apiDomain = $this->apiDomain();
        $moduleApiName = config('services.zoho.po_module_api_name', 'Purchase_Orders');

        if (! $apiDomain) {
            return ['synced' => 0, 'skipped' => 0, 'failed' => 1, 'last_modified' => $since];
        }

        $response = $this->zohoRequest()->get("{$apiDomain}/crm/v3/{$moduleApiName}", [
            'page' => 1,
            'per_page' => min(max($limit, 1), 200),
            'fields' => implode(',', array_unique([
                'id',
                'Modified_Time',
                config('services.zoho.field_po', 'PO_Number'),
                config('services.zoho.field_vendor_name', 'Vendor_Name'),
                config('services.zoho.field_subject', 'Subject'),
                config('services.zoho.field_product_details', 'Product_Details'),
            ])),
            'sort_by' => 'Modified_Time',
            'sort_order' => 'desc',
        ]);

        if (! $response->successful()) {
            return ['synced' => 0, 'skipped' => 0, 'failed' => 1, 'last_modified' => $since];
        }

        $synced = 0;
        $skipped = 0;
        $failed = 0;
        $latest = $since;

        foreach ($response->json('data', []) as $record) {
            $modified = $record['Modified_Time'] ?? null;

            if ($since && $modified && strcmp($modified, $since) <= 0) {
                $skipped++;
                continue;
            }

            $po = $this->syncPurchaseOrderData($this->normalizePurchaseOrderRecord($record));
            $po ? $synced++ : $failed++;

            if ($modified && (! $latest || strcmp($modified, $latest) > 0)) {
                $latest = $modified;
            }
        }

        return ['synced' => $synced, 'skipped' => $skipped, 'failed' => $failed, 'last_modified' => $latest];
    }

    /**
     * @return array{vendors: int, products: int, failed: int}
     */
    public function syncMasterData(int $limit = 200): array
    {
        $apiDomain = $this->apiDomain();

        if (! $apiDomain) {
            return ['vendors' => 0, 'products' => 0, 'failed' => 1];
        }

        return [
            'vendors' => $this->syncVendors($apiDomain, $limit),
            'products' => $this->syncProducts($apiDomain, $limit),
            'failed' => 0,
        ];
    }

    /**
     * @return array{vendors: int, products: int, failed: int}
     */
    public function pushMasterData(): array
    {
        $vendors = 0;
        $products = 0;
        $failed = 0;

        VendorMaster::orderBy('id')->chunk(100, function ($records) use (&$vendors, &$failed) {
            foreach ($records as $vendor) {
                $this->pushVendorMaster($vendor) ? $vendors++ : $failed++;
            }
        });

        SkuMaster::orderBy('id')->chunk(100, function ($records) use (&$products, &$failed) {
            foreach ($records as $sku) {
                $this->pushSkuMaster($sku) ? $products++ : $failed++;
            }
        });

        return ['vendors' => $vendors, 'products' => $products, 'failed' => $failed];
    }

    public function pushVendorMaster(VendorMaster $vendor): bool
    {
        if (! $this->apiDomain() || trim($vendor->vendor_name) === '') {
            return false;
        }

        $phone = trim((string) $vendor->contact_phone);

        $payload = array_filter([
            'Vendor_Name' => $vendor->vendor_name,
            'Email' => $vendor->contact_email,
            'Phone' => preg_match('/\d/', $phone) ? $phone : null,
            'Website' => $vendor->website,
            'Description' => $vendor->description,
        ], fn ($value) => $value !== null && $value !== '');

        return $this->upsertZohoRecord('Vendors', 'Vendor_Name', $vendor->vendor_name, $payload);
    }

    public function pushSkuMaster(SkuMaster $sku): bool
    {
        if (! $this->apiDomain() || trim($sku->sku) === '') {
            return false;
        }

        $payload = array_filter([
            'Product_Name' => $sku->sku,
            'Product_Code' => $sku->product_code,
            'Product_Active' => $sku->active,
            'Unit_Price' => $sku->unit_price !== null ? (float) $sku->unit_price : null,
            'Description' => $sku->description,
        ], fn ($value) => $value !== null && $value !== '');

        if ($sku->vendor_name) {
            $vendor = $this->findOrCreateZohoRecord('Vendors', 'Vendor_Name', $sku->vendor_name);

            if ($vendor) {
                $payload['Vendor_Name'] = ['id' => $vendor['id']];
            }
        }

        return $this->upsertZohoRecord('Products', 'Product_Name', $sku->sku, $payload);
    }

    /**
     * @return array{rfqs: int, vendor_bills: int, finance: int, failed: int}
     */
    public function pushWorkflowData(): array
    {
        $rfqs = 0;
        $vendorBills = 0;
        $finance = 0;
        $failed = 0;

        Rfq::orderBy('id')->chunk(100, function ($records) use (&$rfqs, &$failed) {
            foreach ($records as $rfq) {
                $this->pushRfq($rfq) ? $rfqs++ : $failed++;
            }
        });

        // Vendor bills and finance records move to Zoho Inventory Bills once
        // it's active (see ZohoInventoryService::pushOperationalData) — RFQ
        // has no Inventory equivalent, so it stays on CRM Quotes regardless.
        if (! app(ZohoInventoryService::class)->isActive()) {
            VendorSubmission::orderBy('id')->chunk(100, function ($records) use (&$vendorBills, &$failed) {
                foreach ($records as $submission) {
                    $this->pushVendorSubmission($submission) ? $vendorBills++ : $failed++;
                }
            });

            FinanceRecord::with('gateEntry')->orderBy('id')->chunk(100, function ($records) use (&$finance, &$failed) {
                foreach ($records as $record) {
                    $this->pushFinanceRecord($record) ? $finance++ : $failed++;
                }
            });
        }

        return ['rfqs' => $rfqs, 'vendor_bills' => $vendorBills, 'finance' => $finance, 'failed' => $failed];
    }

    public function pushRfq(Rfq $rfq): bool
    {
        if (! $this->apiDomain() || trim($rfq->sku) === '') {
            return false;
        }

        $product = $this->findOrCreateZohoRecord('Products', 'Product_Name', $rfq->sku);

        if (! $product) {
            return false;
        }

        $subject = "Tan90 RFQ #{$rfq->id} - {$rfq->sku}";
        $payload = [
            'Subject' => $subject,
            'Quote_Stage' => $rfq->status ?: 'Draft',
            'Description' => trim("Vendor: {$rfq->vendor_name}\nNotes: {$rfq->notes}\nAdmin Notes: {$rfq->admin_notes}"),
            'Quoted_Items' => [[
                'Product_Name' => ['id' => $product['id']],
                'Quantity' => max(1, (int) $rfq->quantity),
                'List_Price' => max(0, (float) $rfq->quoted_price),
            ]],
        ];

        return $this->upsertZohoRecord('Quotes', 'Subject', $subject, $payload);
    }

    public function pushVendorSubmission(VendorSubmission $submission): bool
    {
        if (! $this->apiDomain() || trim($submission->invoice_number) === '') {
            return false;
        }

        $productName = $submission->material ?: 'Tan90 Vendor Bill Item';
        $product = $this->findOrCreateZohoRecord('Products', 'Product_Name', $productName);

        if (! $product) {
            return false;
        }

        $subject = 'Tan90 Vendor Bill #'.$submission->id;
        $payload = [
            'Subject' => $subject,
            'Purchase_Order' => $submission->po_number,
            'Status' => $submission->status ?: 'Created',
            'Description' => trim("Vendor: {$submission->vendor_name}\nInvoice: {$submission->invoice_number}\nNote: {$submission->note}"),
            'Invoiced_Items' => [[
                'Product_Name' => ['id' => $product['id']],
                'Quantity' => max(1, (int) $submission->invoice_qty),
                'List_Price' => 0,
            ]],
        ];

        return $this->upsertZohoRecord('Invoices', 'Subject', $subject, $payload);
    }

    public function pushFinanceRecord(FinanceRecord $record): bool
    {
        if (! $this->apiDomain() || trim($record->invoice_number) === '') {
            return false;
        }

        $productName = $record->gateEntry?->material ?: 'Tan90 Finance Item';
        $product = $this->findOrCreateZohoRecord('Products', 'Product_Name', $productName);

        if (! $product) {
            return false;
        }

        $subject = 'Tan90 Finance #'.$record->id;
        $payload = [
            'Subject' => $subject,
            'Purchase_Order' => $record->gateEntry?->po_number,
            'Status' => $record->vendor_status ?: 'Created',
            'Description' => trim("Vendor: {$record->vendor_name}\nInvoice: {$record->invoice_number}\nNotes: {$record->notes}"),
            'Invoiced_Items' => [[
                'Product_Name' => ['id' => $product['id']],
                'Quantity' => max(1, (int) ($record->gateEntry?->invoice_qty ?: 1)),
                'List_Price' => max(0, (float) $record->rate_per_unit),
            ]],
        ];

        return $this->upsertZohoRecord('Invoices', 'Subject', $subject, $payload);
    }

    /**
     * @return array{gate_entries: int, qc_results: int, grn_records: int, bom_records: int, failed: int}
     */
    public function pushOperationalData(): array
    {
        $gateEntries = 0;
        $qcResults = 0;
        $grnRecords = 0;
        $bomRecords = 0;
        $failed = 0;

        GateEntry::with(['qcResult', 'grnRecord'])->orderBy('id')->chunk(100, function ($records) use (&$gateEntries, &$failed) {
            foreach ($records as $record) {
                $this->pushGateEntry($record) ? $gateEntries++ : $failed++;
            }
        });

        QcResult::with('gateEntry')->orderBy('id')->chunk(100, function ($records) use (&$qcResults, &$failed) {
            foreach ($records as $record) {
                $this->pushQcResult($record) ? $qcResults++ : $failed++;
            }
        });

        // GRN moves to Zoho Inventory Purchase Receives once it's active
        // (see ZohoInventoryService::pushOperationalData) — Gate Entry, QC,
        // and BOM/Recipe/Costing have no Inventory equivalent, so they stay
        // on CRM notes regardless.
        if (! app(ZohoInventoryService::class)->isActive()) {
            GrnRecord::with('gateEntry')->orderBy('id')->chunk(100, function ($records) use (&$grnRecords, &$failed) {
                foreach ($records as $record) {
                    $this->pushGrnRecord($record) ? $grnRecords++ : $failed++;
                }
            });
        }

        Bom::with(['finishedGood', 'currentVersion.lines.component'])->orderBy('id')->chunk(100, function ($records) use (&$bomRecords, &$failed) {
            foreach ($records as $record) {
                $this->pushBom($record) ? $bomRecords++ : $failed++;
            }
        });

        Recipe::with(['finishedGood', 'currentVersion'])->orderBy('id')->chunk(100, function ($records) use (&$bomRecords, &$failed) {
            foreach ($records as $record) {
                $this->pushRecipe($record) ? $bomRecords++ : $failed++;
            }
        });

        CostSheet::with('finishedGood')->orderBy('id')->chunk(100, function ($records) use (&$bomRecords, &$failed) {
            foreach ($records as $record) {
                $this->pushCostSheet($record) ? $bomRecords++ : $failed++;
            }
        });

        return ['gate_entries' => $gateEntries, 'qc_results' => $qcResults, 'grn_records' => $grnRecords, 'bom_records' => $bomRecords, 'failed' => $failed];
    }

    public function pushGateEntry(GateEntry $entry): bool
    {
        $po = $entry->po_number ? $this->findRawPurchaseOrder($entry->po_number) : null;

        if (! $po || ! isset($po['id'])) {
            return false;
        }

        $content = implode("\n", array_filter([
            "Gate No: {$entry->gate_no}",
            "Status: {$entry->status}",
            "Vendor: {$entry->vendor_name}",
            "Invoice: {$entry->invoice_number}",
            "Material: {$entry->material}",
            "Invoice Qty: {$entry->invoice_qty}",
            "Vehicle: {$entry->vehicle_number}",
            "Driver: {$entry->driver_name}",
            "Transporter: {$entry->transporter}",
            "Remarks: {$entry->remarks}",
        ]));

        return $this->upsertZohoNote('Purchase_Orders', (string) $po['id'], "Tan90 Gate Entry #{$entry->id}", $content);
    }

    public function pushQcResult(QcResult $result): bool
    {
        $entry = $result->gateEntry;
        $po = $entry?->po_number ? $this->findRawPurchaseOrder($entry->po_number) : null;

        if (! $po || ! isset($po['id'])) {
            return false;
        }

        $content = implode("\n", array_filter([
            "SKU: {$result->sku}",
            "PO Qty: {$result->po_qty}",
            "Invoice Qty: {$result->invoice_qty}",
            "Physical Received: {$result->physical_received}",
            "Accepted Qty: {$result->accepted_qty}",
            "QC Hold Qty: {$result->qc_hold_qty}",
            "Defective Qty: {$result->defective_qty}",
            "Rejected Qty: {$result->rejected_qty}",
            "Missing Qty: {$result->missing_qty}",
            "Reasons: {$result->qc_reasons}",
            "Return Status: {$result->return_status}",
        ]));

        return $this->upsertZohoNote('Purchase_Orders', (string) $po['id'], "Tan90 QC Result #{$result->id}", $content);
    }

    public function pushGrnRecord(GrnRecord $record): bool
    {
        $entry = $record->gateEntry;
        $po = $entry?->po_number ? $this->findRawPurchaseOrder($entry->po_number) : null;

        if (! $po || ! isset($po['id'])) {
            return false;
        }

        $content = implode("\n", array_filter([
            "SKU: {$record->sku}",
            "PO Qty: {$record->po_qty}",
            "Invoice Qty: {$record->invoice_qty}",
            "Physical Received: {$record->physical_received}",
            "Accepted Qty: {$record->accepted_qty}",
            "QC Hold Qty: {$record->qc_hold_qty}",
            "Rejected Qty: {$record->rejected_qty}",
            "Missing Qty: {$record->missing_qty}",
            "Suggested Bin: {$record->suggested_bin}",
            'Posted: '.($record->posted ? 'Yes' : 'No'),
        ]));

        return $this->upsertZohoNote('Purchase_Orders', (string) $po['id'], "Tan90 GRN Record #{$record->id}", $content);
    }

    public function pushBom(Bom $bom): bool
    {
        $product = $this->findOrCreateZohoRecord('Products', 'Product_Name', $bom->finishedGood?->name ?: $bom->code);

        if (! $product) {
            return false;
        }

        $lines = $bom->currentVersion?->lines?->map(fn ($line) => trim(($line->component?->masking_code ?: $line->component?->name ?: 'Sub BOM').' x '.$line->quantity.' '.$line->uom))->implode("\n");
        $content = "BOM Code: {$bom->code}\nType: {$bom->bom_type}\nStatus: {$bom->status}\nApproval: {$bom->approval_status}\nLines:\n{$lines}";

        return $this->upsertZohoNote('Products', $product['id'], "Tan90 BOM #{$bom->id}", $content);
    }

    public function pushRecipe(Recipe $recipe): bool
    {
        $product = $this->findOrCreateZohoRecord('Products', 'Product_Name', $recipe->finishedGood?->name ?: $recipe->name);

        if (! $product) {
            return false;
        }

        $content = "Recipe Code: {$recipe->code}\nName: {$recipe->name}\nTolerance: {$recipe->formula_tolerance_percent}%\nStatus: {$recipe->status}\nApproval: {$recipe->approval_status}";

        return $this->upsertZohoNote('Products', $product['id'], "Tan90 Recipe #{$recipe->id}", $content);
    }

    public function pushCostSheet(CostSheet $sheet): bool
    {
        $product = $this->findOrCreateZohoRecord('Products', 'Product_Name', $sheet->finishedGood?->name ?: $sheet->code);

        if (! $product) {
            return false;
        }

        $content = "Cost Sheet: {$sheet->code}\nPeriod: {$sheet->cost_period}\nMaterial: {$sheet->material_cost}\nLabor: {$sheet->labor_cost}\nMachine: {$sheet->machine_cost}\nUtility: {$sheet->utility_cost}\nOverhead: {$sheet->overhead_cost}\nStandard Cost: {$sheet->total_standard_cost}\nActual Cost: {$sheet->total_actual_cost}\nStatus: {$sheet->status}\nApproval: {$sheet->approval_status}";

        return $this->upsertZohoNote('Products', $product['id'], "Tan90 Cost Sheet #{$sheet->id}", $content);
    }

    /**
     * @return array{success: bool, action: string, status: ?int, message: string, zoho_id: ?string}
     */
    public function pushPurchaseOrder(PurchaseOrder $po): array
    {
        if (! config('services.zoho.write_enabled', true)) {
            return ['success' => false, 'action' => 'disabled', 'status' => null, 'message' => 'Zoho writes are disabled.', 'zoho_id' => null];
        }

        $apiDomain = $this->apiDomain();
        $moduleApiName = config('services.zoho.po_module_api_name', 'Purchase_Orders');

        if (! $apiDomain) {
            return ['success' => false, 'action' => 'auth_failed', 'status' => null, 'message' => 'Zoho token refresh failed.', 'zoho_id' => null];
        }

        $existing = $this->findRawPurchaseOrder($po->po_number);
        $recordId = $existing['id'] ?? null;

        if (! $recordId && ! config('services.zoho.create_enabled', false)) {
            return [
                'success' => true,
                'action' => 'skipped_create',
                'status' => null,
                'message' => 'Zoho create is disabled until required lookup fields are mapped.',
                'zoho_id' => null,
            ];
        }

        $po->loadMissing('lines');
        $payload = $this->purchaseOrderWritePayload($po, ! $recordId);

        if (! $payload) {
            return [
                'success' => false,
                'action' => 'mapping_failed',
                'status' => null,
                'message' => $this->lastLookupError ?: 'Zoho Vendor/Product lookup mapping failed.',
                'zoho_id' => $recordId,
            ];
        }

        $response = $recordId
            ? $this->zohoRequest()->put("{$apiDomain}/crm/v3/{$moduleApiName}/{$recordId}", ['data' => [$payload]])
            : $this->zohoRequest()->post("{$apiDomain}/crm/v3/{$moduleApiName}", ['data' => [$payload]]);

        $result = $response->json('data.0') ?? [];
        $success = $response->successful() && in_array($result['status'] ?? null, ['success'], true);

        return [
            'success' => $success,
            'action' => $recordId ? 'updated' : 'created',
            'status' => $response->status(),
            'message' => $result['message'] ?? $response->body(),
            'zoho_id' => $result['details']['id'] ?? $recordId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseOrderWritePayload(PurchaseOrder $po, bool $creating = false): array
    {
        $this->lastLookupError = null;

        $payload = [
            config('services.zoho.field_po', 'PO_Number') => $po->po_number,
            config('services.zoho.field_subject', 'Subject') => $po->subject ?: $po->po_number,
        ];

        if ($creating || config('services.zoho.write_vendor_name', false)) {
            $vendor = $this->findOrCreateZohoRecord('Vendors', 'Vendor_Name', $po->vendor_name ?: 'Tan90 Vendor');

            if (! $vendor) {
                return [];
            }

            $payload[config('services.zoho.field_vendor_name', 'Vendor_Name')] = ['id' => $vendor['id']];
        }

        if ($creating || config('services.zoho.write_product_details', false)) {
            $lines = $po->lines->isNotEmpty() ? $po->lines : collect([(object) [
                'product' => $po->subject ?: $po->po_number,
                'quantity' => 1,
                'list_price' => 0,
            ]]);

            $payload[config('services.zoho.field_product_details', 'Purchase_Items')] = $lines->map(function ($line) {
                $product = $this->findOrCreateZohoRecord('Products', 'Product_Name', $line->product ?: 'Tan90 Purchase Item');

                if (! $product) {
                    return null;
                }

                return [
                    'Product_Name' => ['id' => $product['id']],
                    'Quantity' => max(1, (int) $line->quantity),
                    'List_Price' => max(0, (float) $line->list_price),
                ];
            })->filter()->values()->all();

            if ($payload[config('services.zoho.field_product_details', 'Purchase_Items')] === []) {
                return [];
            }
        }

        return $payload;
    }

    /**
     * @return array{id: string, name?: string}|null
     */
    private function findOrCreateZohoRecord(string $module, string $nameField, string $name): ?array
    {
        $apiDomain = $this->apiDomain();
        $name = trim($name);

        if (! $apiDomain || $name === '') {
            return null;
        }

        $criteria = "({$nameField}:equals:{$name})";
        $existing = $this->zohoRequest()
            ->get("{$apiDomain}/crm/v3/{$module}/search", ['criteria' => $criteria]);

        if ($existing->successful() && $existing->json('data.0.id')) {
            return [
                'id' => (string) $existing->json('data.0.id'),
                'name' => (string) ($existing->json("data.0.{$nameField}") ?? $name),
                'created' => false,
            ];
        }

        $created = $this->zohoRequest()
            ->post("{$apiDomain}/crm/v3/{$module}", ['data' => [[$nameField => $name]]]);

        if ($created->json('data.0.code') === 'DUPLICATE_DATA' && $created->json('data.0.details.duplicate_record.id')) {
            return [
                'id' => (string) $created->json('data.0.details.duplicate_record.id'),
                'name' => $name,
                'created' => false,
            ];
        }

        if (! $created->successful() || ! $created->json('data.0.details.id')) {
            $this->lastLookupError = "Zoho {$module} lookup failed for {$name}: ".$created->body();

            return null;
        }

        return [
            'id' => (string) $created->json('data.0.details.id'),
            'name' => $name,
            'created' => true,
        ];
    }

    private function upsertZohoRecord(string $module, string $nameField, string $name, array $payload): bool
    {
        $apiDomain = $this->apiDomain();
        $record = $this->findOrCreateZohoRecord($module, $nameField, $name);

        if (! $apiDomain || ! $record) {
            return false;
        }

        if ($record['created'] ?? false) {
            return true;
        }

        $response = $this->zohoRequest()
            ->put("{$apiDomain}/crm/v3/{$module}/{$record['id']}", ['data' => [$payload]]);

        if ($response->json('data.0.code') === 'DUPLICATE_DATA') {
            return true;
        }

        return $response->successful() && in_array($response->json('data.0.status'), ['success'], true);
    }

    private function upsertZohoNote(string $parentModule, string $parentId, string $title, string $content): bool
    {
        $apiDomain = $this->apiDomain();

        if (! $apiDomain || trim($parentId) === '' || trim($title) === '') {
            return false;
        }

        $notes = $this->zohoRequest()
            ->get("{$apiDomain}/crm/v3/{$parentModule}/{$parentId}/Notes", ['fields' => 'Note_Title,Note_Content,id']);

        $existingId = null;

        if ($notes->successful()) {
            foreach ($notes->json('data', []) as $note) {
                if (($note['Note_Title'] ?? '') === $title) {
                    $existingId = $note['id'] ?? null;
                    break;
                }
            }
        }

        $payload = ['Note_Title' => $title, 'Note_Content' => $content ?: 'Synced from Tan90.'];
        $response = $existingId
            ? $this->zohoRequest()->put("{$apiDomain}/crm/v3/Notes/{$existingId}", ['data' => [$payload]])
            : $this->zohoRequest()->post("{$apiDomain}/crm/v3/{$parentModule}/{$parentId}/Notes", ['data' => [$payload]]);

        return $response->successful() && in_array($response->json('data.0.status'), ['success'], true);
    }

    private function syncPurchaseOrderData(?array $zohoPo): ?PurchaseOrder
    {
        if (! $zohoPo) {
            return null;
        }

        $po = PurchaseOrder::withoutEvents(fn () => PurchaseOrder::updateOrCreate(
            ['po_number' => $zohoPo['poNumber']],
            [
                'subject' => $zohoPo['subject'] ?: null,
                'vendor_name' => $zohoPo['vendorName'] ?: 'Zoho Vendor',
                'status' => 'Approved',
                'description' => 'Synced from Zoho CRM Purchase Orders.',
            ],
        ));

        $product = $zohoPo['material'] ?: 'Zoho Purchase Item';
        $quantity = max(1, (int) $zohoPo['quantity']);
        $listPrice = max(0, (float) $zohoPo['listPrice']);

        PurchaseOrderLine::withoutEvents(function () use ($po, $product, $quantity, $listPrice) {
            $po->lines()->delete();
            $po->lines()->create([
                'product' => $product,
                'quantity' => $quantity,
                'list_price' => $listPrice,
            ]);
        });

        return $po->load('lines');
    }

    private function syncVendors(string $apiDomain, int $limit): int
    {
        $response = $this->zohoRequest()->get("{$apiDomain}/crm/v3/Vendors", [
            'page' => 1,
            'per_page' => min(max($limit, 1), 200),
            'fields' => 'Vendor_Name,Email,Phone,Website,Description,Owner,Address,Modified_Time',
            'sort_by' => 'Modified_Time',
            'sort_order' => 'desc',
        ]);

        if (! $response->successful()) {
            return 0;
        }

        $count = 0;

        foreach ($response->json('data', []) as $record) {
            $name = trim((string) ($record['Vendor_Name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $existing = VendorMaster::where('vendor_name', $name)->first();

            VendorMaster::withoutEvents(fn () => VendorMaster::updateOrCreate(
                ['vendor_name' => $name],
                [
                    'gst_number' => $existing?->gst_number ?: 'ZOHO-N/A',
                    'contact_phone' => (string) (($record['Phone'] ?? $existing?->contact_phone) ?: 'N/A'),
                    'contact_email' => $record['Email'] ?? null,
                    'category' => $existing?->category ?: 'Zoho Vendor',
                    'active' => true,
                    'vendor_owner' => $record['Owner']['name'] ?? null,
                    'website' => $record['Website'] ?? null,
                    'address_street' => $record['Address'] ?? null,
                    'description' => $record['Description'] ?? null,
                ],
            ));

            $count++;
        }

        return $count;
    }

    private function syncProducts(string $apiDomain, int $limit): int
    {
        $response = $this->zohoRequest()->get("{$apiDomain}/crm/v3/Products", [
            'page' => 1,
            'per_page' => min(max($limit, 1), 200),
            'fields' => 'Product_Name,Product_Code,Product_Active,Unit_Price,Description,Vendor_Name,Owner,Product_Category,Usage_Unit,Qty_in_Stock,Qty_in_Demand,Modified_Time',
            'sort_by' => 'Modified_Time',
            'sort_order' => 'desc',
        ]);

        if (! $response->successful()) {
            return 0;
        }

        $count = 0;

        foreach ($response->json('data', []) as $record) {
            $name = trim((string) ($record['Product_Name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $existing = SkuMaster::where('sku', $name)->first();
            $vendorField = $record['Vendor_Name'] ?? null;
            $vendorName = is_array($vendorField) ? ($vendorField['name'] ?? null) : $vendorField;

            SkuMaster::withoutEvents(fn () => SkuMaster::updateOrCreate(
                ['sku' => $name],
                [
                    'category' => (($record['Product_Category'] ?? $existing?->category) ?: 'Zoho Product'),
                    'unit' => (($record['Usage_Unit'] ?? $existing?->unit) ?: 'NOS'),
                    'mapped' => true,
                    'product_owner' => $record['Owner']['name'] ?? null,
                    'product_code' => $record['Product_Code'] ?? null,
                    'active' => (bool) ($record['Product_Active'] ?? true),
                    'vendor_name' => $vendorName,
                    'unit_price' => $record['Unit_Price'] ?? null,
                    'quantity_in_stock' => $record['Qty_in_Stock'] ?? null,
                    'quantity_in_demand' => $record['Qty_in_Demand'] ?? null,
                    'description' => $record['Description'] ?? null,
                ],
            ));

            $count++;
        }

        return $count;
    }

    private function apiDomain(): ?string
    {
        if (Cache::get('zoho_api_domain') && $this->accessToken()) {
            return Cache::get('zoho_api_domain');
        }

        return $this->accessToken() ? Cache::get('zoho_api_domain') : null;
    }

    private function zohoRequest()
    {
        $token = $this->accessToken();

        return Http::withHeaders(['Authorization' => "Zoho-oauthtoken {$token}"]);
    }

    private function findRawPurchaseOrderById(string $recordId): ?array
    {
        $apiDomain = Cache::get('zoho_api_domain');
        $moduleApiName = config('services.zoho.po_module_api_name', 'Purchase_Orders');

        if (! $apiDomain) {
            return null;
        }

        $response = $this->zohoRequest()
            ->get("{$apiDomain}/crm/v3/{$moduleApiName}/{$recordId}");

        return $response->successful() ? $response->json('data.0') : null;
    }
}
