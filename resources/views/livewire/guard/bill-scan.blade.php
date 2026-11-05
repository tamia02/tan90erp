<?php

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\ValidationIssue;
use App\Models\VendorMaster;
use App\Models\VendorSubmission;
use App\Services\AuditLogger;
use App\Services\GateValidationService;
use App\Services\Inventory\WarehouseStockResolver;
use App\Services\PurchaseOrderAmountCalculator;
use App\Support\SlaDirectives;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public string $entryType = 'inward';

    // Outward gate pass made from an Outward Shipment (Inventory > Outward
    // Shipment, documents complete). Null = the old free outward pass.
    public ?int $outwardShipmentId = null;

    public bool $billScanned = false;

    public bool $ocrReady = false;

    #[Validate('nullable|file|mimes:jpg,jpeg,png,pdf|max:10240')]
    public $billFile = null;

    public string $gps = '';

    public ?array $saved = null;

    public bool $fetched = false;

    public string $fetchedSource = '';

    public array $availableInvoices = [];

    /** @var array<int, array{po_number: string, vendor_name: string, status: string}> */
    public array $poSuggestions = [];

    public string $selectedInvoiceNumber = '';

    public string $poNumber = '';

    public string $poBillDate = '';

    public string $vendorName = '';

    public string $vendorGst = '';

    public string $invoiceNumber = '';

    public string $invoiceQty = '';

    public string $invoiceAmount = '';

    public string $rate = '';

    public string $material = '';

    public string $transporter = '';

    public string $vehicleNumber = '';

    public string $driverName = '';

    public string $driverPhone = '';

    public string $location = 'bhiwandi';

    public function mount(): void
    {
        // Start on the Guard's own site (Bhiwandi stays the default for all-site users).
        if (! in_array($this->location, $this->siteLocationKeys(), true)) {
            $this->location = $this->siteLocationKeys()[0];
        }
    }

    public string $remarks = '';

    // Deliberately separate from driverName/poNumber/material rather than
    // reusing them under a different label: those three are shared across
    // inward/outward/visitor, which is exactly what made "Visitor Name" and
    // "Person To Meet" feel linked to the driver/PO fields for anyone
    // switching between entry types -- they were, literally, the same
    // underlying property.
    public string $visitorName = '';

    public string $visitorHostId = '';

    // Outward has two moments at the gate: Truck In (arriving to load - only
    // driver, vehicle and whom they meet) and Truck Out (leaving - Guard sees
    // what is going out and confirms the exit, which dispatches the stock).
    public string $outwardMode = 'in';
    // Truck In: the old full outward form (package / invoice / material) on request.
    public bool $outwardFullForm = false;
    // "Meeting whom" for inward / outward - the staff member the driver came to see.
    public string $contactUserId = '';

    public string $visitPurpose = '';

    public array $visitPurposeOptions = ['Meeting', 'Service / Maintenance Visit', 'Audit / Inspection'];

    public array $documents = ['invoice' => false, 'eway' => false, 'lr' => false, 'pod' => false];

    public array $productLines = [];

    private const LOCATIONS = [
        'delhi' => ['name' => 'DELHI (MU)', 'code' => 'DELHI-TAN90-MU', 'company' => 'TAN90', 'contact' => 'Ram Prakash', 'phone' => '+91 92056 54865', 'address' => '96QX+3XH Pali, Haryana', 'gps' => '28.3078 N, 76.8450 E'],
        'chennai' => ['name' => 'CHENNAI (MU)', 'code' => 'CHENNAI-TAN90-MU', 'company' => 'TAN90', 'contact' => 'Karthick', 'phone' => '+91 89258 00797', 'address' => '75/1A1, Poonamallee Bypass Rd, Senneer Kuppam, Chennai, Tamil Nadu 600077', 'gps' => '13.0646 N, 80.1016 E'],
        'bhiwandi' => ['name' => 'Bhiwandi WH (MU)', 'code' => 'Mumbai-TAN90-MU', 'company' => 'TAN90', 'contact' => 'Shamim', 'phone' => '+91 89258 46275', 'address' => 'S.No. 90 H No 2 situated at Vadpa, Bhiwandi, Maharashtra 421302', 'gps' => '19.2967 N, 73.0631 E'],
    ];

    // A Guard tied to a site only makes passes for that site's gate.
    private function siteLocationKeys(): array
    {
        $site = \App\Support\SiteLocation::current();
        if ($site === null) {
            return array_keys(self::LOCATIONS);
        }
        $keys = array_values(array_filter(array_keys(self::LOCATIONS), fn ($key) => \App\Support\SiteLocation::idForName(self::LOCATIONS[$key]['name']) === $site));

        return $keys ?: array_keys(self::LOCATIONS);
    }

    public function updatedLocation(string $value): void
    {
        if (! in_array($value, $this->siteLocationKeys(), true)) {
            $this->location = $this->siteLocationKeys()[0];
        }
    }

    private function nextGateNumber(): string
    {
        $prefix = match ($this->entryType) {
            'outward' => 'OUT',
            'visitor' => 'VIS',
            default => 'IN',
        };
        $year = now()->format('Y');
        $needle = "GP-{$prefix}-{$year}-";
        $last = GateEntry::withoutSiteScope()->where('gate_no', 'like', $needle.'%') // numbers are unique across sites
            ->pluck('gate_no')
            // Stringable must be converted explicitly before casting on PHP 8;
            // an implicit (int) cast throws and prevents every new gate pass
            // from being saved once sequential numbering is enabled.
            ->map(fn ($number) => (int) (string) str($number)->afterLast('-'))
            ->max() ?? 0;

        return $needle.str_pad((string) ($last + 1), 6, '0', STR_PAD_LEFT);
    }

    public function selectOutwardShipment(int $id): void
    {
        $shipment = \App\Models\Outward\OutwardShipment::with('lines.item')->whereKey($id)->whereIn('status', ['documents_pending', 'ready'])->whereNull('gate_entry_id')->first();
        if (! $shipment) {
            $this->addError('outwardShipmentId', 'That shipment already has a truck or is no longer open - refresh the list.');

            return;
        }
        $this->outwardShipmentId = $shipment->id;
        $this->fetched = false; // not a PO fetch - skip the PO line checks
        $this->poNumber = $shipment->number;
        $this->vendorName = $shipment->to_name;
        $this->vendorGst = (string) $shipment->to_gstin;
        $this->invoiceNumber = (string) ($shipment->invoice_number ?: $shipment->challan_number);
        $this->invoiceAmount = (string) $shipment->total_value;
        $this->invoiceQty = (string) max(1, (int) ceil((float) $shipment->lines->sum('qty')));
        $this->material = $shipment->lines->map(fn ($line) => $line->item?->displayName())->filter()->implode(' + ');
        $this->productLines = $shipment->lines->map(fn ($line) => [
            'po_line_id' => null, 'sku' => (string) $line->item?->sku, 'name' => (string) $line->item?->displayName(),
            'qty' => (float) $line->qty, 'uom' => (string) $line->item?->unit, 'unit_price' => (float) $line->rate,
            'remarks' => "Outward Shipment {$shipment->number}",
        ])->values()->all();
        if ($shipment->vehicle_number) {
            $this->vehicleNumber = $shipment->vehicle_number;
        }
        if ($shipment->transporter) {
            $this->transporter = $shipment->transporter;
        }
        foreach (self::LOCATIONS as $key => $location) {
            if (str_contains(mb_strtolower((string) $shipment->from_name), $key)) {
                $this->location = $key;
            }
        }
        $this->resetValidation();
    }

    public function clearOutwardShipment(): void
    {
        $this->outwardShipmentId = null;
        foreach (['poNumber', 'vendorName', 'vendorGst', 'invoiceNumber', 'invoiceAmount', 'invoiceQty', 'material'] as $field) {
            $this->{$field} = '';
        }
        $this->productLines = [];
    }

    public function with(): array
    {
        $locationDetails = array_intersect_key(self::LOCATIONS, array_flip($this->siteLocationKeys()));
        foreach ($locationDetails as $key => $location) {
            $siteId = \App\Support\SiteLocation::idForName($location['name']);
            $locationDetails[$key]['warehouseLabel'] = WarehouseStockResolver::activeAt($siteId)
                ->map(fn ($warehouse) => $warehouse->code.' — '.$warehouse->name)
                ->implode(', ') ?: 'No active warehouse linked';
        }
        $selectedLocation = $locationDetails[$this->location]
            ?? (reset($locationDetails) ?: self::LOCATIONS['bhiwandi'] + ['warehouseLabel' => 'No active warehouse linked']);

        return [
            'readyShipments' => $this->entryType === 'outward' && ! $this->outwardShipmentId
                ? app(\App\Services\Outward\OutwardShipmentService::class)->openForGate()
                : collect(),
            'linkedShipment' => $this->outwardShipmentId ? \App\Models\Outward\OutwardShipment::find($this->outwardShipmentId) : null,
            'isVisitor' => $this->entryType === 'visitor',
            'modeLabel' => $this->modeLabel(),
            'selectedLocation' => $selectedLocation,
            'locationDetails' => $locationDetails,
            'documentCount' => collect($this->documents)->filter()->count(),
            'documentSummary' => $this->documentSummary(),
            'hostOptions' => in_array($this->entryType, ['visitor', 'inward', 'outward'], true)
                ? User::whereNotNull('role')->where('role', '!=', Role::Vendor)->orderBy('name')->get(['id', 'name', 'role', 'phone'])
                : collect(),
            'leavingTrucks' => $this->entryType === 'outward' && $this->outwardMode === 'out'
                ? GateEntry::with(['outwardShipment.lines.item', 'contactUser'])->where('entry_type', 'outward')
                    ->whereIn('status', ['loaded', 'loading', 'dock_assigned', 'validated', 'pending_validation'])
                    ->orderByRaw("status = 'loaded' DESC")->latest('id')->limit(30)->get()
                : collect(),
        ];
    }

    public function setEntryType(string $type): void
    {
        if (! in_array($type, ['inward', 'outward', 'visitor'], true)) {
            return;
        }

        $this->resetForm();
        $this->entryType = $type;
    }

    public function setOutwardMode(string $mode): void
    {
        if (in_array($mode, ['in', 'out'], true)) {
            $this->resetForm();
            $this->outwardMode = $mode;
        }
    }

    // Truck Out: the loaded truck leaves; with an Outward Shipment this dispatches the stock.
    public function confirmTruckExit(int $gateId): void
    {
        $gate = GateEntry::where('entry_type', 'outward')->findOrFail($gateId);
        try {
            $shipment = app(\App\Services\Outward\GateExitService::class)->confirm($gate, auth()->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('exit.'.$gateId, collect($e->errors())->flatten()->implode(' '));

            return;
        }
        session()->flash('success', "Vehicle exit confirmed for {$gate->gate_no}. Outward dispatch is complete.".($shipment ? " {$shipment->number}: stock has been deducted." : ''));
    }

    public function useGps(): void
    {
        $selected = self::LOCATIONS[$this->location] ?? self::LOCATIONS['bhiwandi'];
        $this->gps = $selected['gps'].' ('.$selected['code'].')';
    }

    /** Fires once Livewire finishes uploading the selected file, so
     * billScanned only flips to true after a real file arrives — not the
     * moment the picker opens, which is all the old wire:click did. */
    public function updatedBillFile(): void
    {
        $this->validateOnly('billFile');
        $this->billScanned = (bool) $this->billFile;
    }

    /** Apply one vendor submission as the single source of truth for this gate entry. */
    private function applySubmission(VendorSubmission $submission, string $source = 'vendor submission'): void
    {
        $po = PurchaseOrder::with('lines')->where('po_number', $submission->po_number)->first();
        $line = $po?->primaryLine();
        $this->selectedInvoiceNumber = (string) $submission->invoice_number;
        $this->invoiceNumber = (string) $submission->invoice_number;
        $this->poNumber = $submission->po_number;
        $this->vendorName = $submission->vendor_name;
        $this->poBillDate = $submission->invoice_date?->format('Y-m-d') ?? '';
        $this->vendorGst = (string) (VendorMaster::where('vendor_name', $submission->vendor_name)->value('gst_number') ?? '');
        $submittedLineQuantities = is_array($submission->line_quantities) ? $submission->line_quantities : [];
        $legacyMultiSku = $po && $po->lines->count() > 1 && $submittedLineQuantities === [];
        if ($po && $submittedLineQuantities !== []) {
            $this->productLines = $po->lines
                ->filter(fn ($poLine) => (float) ($submittedLineQuantities[$poLine->id] ?? 0) > 0)
                ->map(fn ($poLine) => [
                    'po_line_id' => (int) $poLine->id,
                    'sku' => $poLine->product,
                    'name' => $poLine->product,
                    'qty' => (string) $submittedLineQuantities[$poLine->id],
                    'uom' => $poLine->uom ?? 'PCS',
                    'unit_price' => (float) $poLine->list_price,
                    'remarks' => 'Vendor invoice line',
                ])->values()->all();
        } elseif ($po && $po->lines->count() > 1) {
            $poProducts = $po->lines->pluck('product')->filter()->implode(' + ');
            $this->productLines = [[
                'sku' => (string) ($poProducts ?: 'Multi-SKU legacy invoice'),
                'name' => 'Multi-SKU legacy invoice',
                'qty' => (string) ($submission->invoice_qty ?: 1),
                'uom' => 'Mixed / review invoice',
                'unit_price' => null,
                'remarks' => 'Historic submission has no stored per-SKU quantity split; review its invoice before QC.',
            ]];
        } else {
            $quantity = (float) ($submission->invoice_qty ?: ($line?->quantity ?? 1));
            $this->productLines = $po?->lines->map(fn ($poLine) => [
                'po_line_id' => (int) $poLine->id,
                'sku' => $poLine->product,
                'name' => $poLine->product,
                'qty' => (string) $quantity,
                'uom' => $poLine->uom ?? 'PCS',
                'unit_price' => (float) $poLine->list_price,
                'remarks' => 'Vendor invoice line',
            ])->values()->all() ?? [];
        }

        $quantity = (float) ($submission->invoice_qty ?: ($line?->quantity ?? 1));
        $this->invoiceQty = (string) $quantity;
        $this->rate = (string) ($line?->list_price ?? 0);
        $this->invoiceAmount = (string) ($submission->invoice_amount ?: ($quantity * (float) ($line?->list_price ?? 0)));
        // Some older vendor submissions contain no material/SKU text even
        // though the PO line is complete. Keep autofill authoritative but
        // never leave Guard's required material field blank in that case.
        $this->material = trim((string) ($legacyMultiSku
            ? ($po?->lines->pluck('product')->filter()->implode(' + ') ?: 'Multi-SKU legacy invoice')
            : ($submission->material ?: ($line?->product ?? 'Purchase Order Item'))));
        $this->fetchedSource = $source;
        $this->fetched = true;
    }

    /** Apply one released PO to every PO-controlled Guard field. */
    private function applyPurchaseOrder(PurchaseOrder $po, string $source = 'PO Master'): void
    {
        $lines = $po->lines;
        $totalQty = (float) $lines->sum('quantity');
        $totalAmount = app(PurchaseOrderAmountCalculator::class)->amounts(
            $po,
            $lines->mapWithKeys(fn ($line) => [(int) $line->id => (float) $line->quantity])->all(),
        )['total'];
        $products = $lines->map(fn ($line) => $line->documentProduct())->filter()->values();
        $primary = $po->primaryLine();

        $this->poNumber = (string) $po->po_number;
        $this->vendorName = (string) $po->vendor_name;
        $this->poBillDate = $po->po_date?->format('Y-m-d') ?? '';
        $this->vendorGst = (string) (VendorMaster::where('vendor_name', $po->vendor_name)->value('gst_number') ?? '');
        $this->invoiceQty = (string) $totalQty;
        $this->rate = $lines->count() === 1 ? (string) ($primary?->list_price ?? 0) : '';
        $this->invoiceAmount = (string) $totalAmount;
        $this->material = $products->implode(' + ') ?: 'Purchase Order Item';
        $this->productLines = $lines->map(fn ($line) => [
            'po_line_id' => (int) $line->id,
            'sku' => $line->product,
            'name' => $line->documentProduct(),
            'qty' => (string) $line->quantity,
            'ordered_qty' => (float) $line->quantity,
            'uom' => $line->uom ?? 'PCS',
            'remarks' => 'PO-linked item',
            'unit_price' => (float) $line->list_price,
        ])->values()->all();
        $this->fetchedSource = $source;
        $this->fetched = true;
    }

    public function updatedInvoiceQty(?string $value): void
    {
        if ($this->entryType !== 'outward' || ! $this->fetched || count($this->productLines) !== 1) {
            return;
        }

        $this->productLines[0]['qty'] = trim((string) $value);
        $this->syncOutwardShipmentTotals();
    }

    public function updatedProductLines(mixed $value, ?string $key = null): void
    {
        if ($this->entryType !== 'outward' || ! $this->fetched || ! str_ends_with((string) $key, '.qty')) {
            return;
        }

        $this->syncOutwardShipmentTotals();
    }

    /** Keep the outward header quantity/value aligned with the actual SKU split. */
    private function syncOutwardShipmentTotals(): bool
    {
        if ($this->entryType !== 'outward' || ! $this->fetched) {
            return true;
        }

        $this->resetErrorBag('productLines');
        $this->resetErrorBag('invoiceQty');
        $this->resetErrorBag('poNumber');
        $po = PurchaseOrder::with('lines')->where('po_number', $this->poNumber)->first();
        if (! $po || $po->lines->isEmpty()) {
            $this->addError('poNumber', 'Fetch the PO again before setting shipment quantities.');

            return false;
        }

        $allocations = [];
        foreach ($po->lines as $poLine) {
            $matches = collect($this->productLines)
                ->filter(fn (array $line) => (int) ($line['po_line_id'] ?? 0) === (int) $poLine->id);
            if ($matches->count() !== 1) {
                $this->addError('productLines', 'Each PO item must appear once with its own dispatched quantity. Fetch the PO again.');

                return false;
            }

            $index = $matches->keys()->first();
            $rawQuantity = trim((string) ($matches->first()['qty'] ?? ''));
            if ($rawQuantity !== '' && (! ctype_digit($rawQuantity) || (float) $rawQuantity > (float) $poLine->quantity)) {
                $this->addError("productLines.{$index}.qty", 'Enter a whole dispatched quantity from 0 up to the PO quantity of '.number_format((float) $poLine->quantity, 0).'.');

                return false;
            }

            $allocations[(int) $poLine->id] = (float) ($rawQuantity === '' ? 0 : $rawQuantity);
        }

        if (count($this->productLines) !== $po->lines->count()) {
            $this->addError('productLines', 'The fetched PO items changed. Fetch the PO again before saving.');

            return false;
        }

        $shipmentQty = array_sum($allocations);
        if ($shipmentQty < 1) {
            $this->addError('invoiceQty', 'Enter the quantity being dispatched on this vehicle.');

            return false;
        }

        $amounts = app(PurchaseOrderAmountCalculator::class)->amounts($po, $allocations);
        $this->invoiceQty = (string) (int) $shipmentQty;
        $this->invoiceAmount = number_format($amounts['total'], 2, '.', '');
        $this->material = $po->lines
            ->filter(fn ($line) => ($allocations[(int) $line->id] ?? 0) > 0)
            ->pluck('product')
            ->implode(' + ');

        return true;
    }

    /** Keep PO typing non-destructive: show suggestions, then fetch on selection/button. */
    public function updatedPoNumber(?string $value): void
    {
        if (! in_array($this->entryType, ['inward', 'outward'], true)) {
            return;
        }

        $this->poNumber = trim((string) $value);
        $this->refreshPoSuggestions($this->poNumber);
    }

    private function refreshPoSuggestions(?string $value): void
    {
        $value = trim((string) $value);
        if (mb_strlen($value) < 2) {
            $this->poSuggestions = [];

            return;
        }

        $needle = '%'.addcslashes($value, '%_\\').'%';
        $compact = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($value));
        $compactNeedle = '%'.addcslashes((string) $compact, '%_\\').'%';

        $this->poSuggestions = PurchaseOrder::query()
            ->whereNotNull('released_at')
            ->where(function ($query) use ($needle, $compactNeedle) {
                $query->where('po_number', 'like', $needle)
                    ->orWhereRaw("REPLACE(REPLACE(UPPER(po_number), ' ', ''), '-', '') LIKE ?", [$compactNeedle]);
            })
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['po_number', 'vendor_name', 'status'])
            ->map(fn (PurchaseOrder $po) => [
                'po_number' => (string) $po->po_number,
                'vendor_name' => (string) ($po->vendor_name ?: 'Vendor not set'),
                'status' => (string) ($po->status ?: 'Released'),
            ])
            ->values()
            ->all();
    }

    private function findPurchaseOrder(string $value): ?PurchaseOrder
    {
        $compact = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($value)));
        if ($compact === '') {
            return null;
        }

        return PurchaseOrder::with('lines')
            ->whereNotNull('released_at')
            ->whereRaw("REPLACE(REPLACE(UPPER(po_number), ' ', ''), '-', '') = ?", [$compact])
            ->first();
    }

    public function selectPoSuggestion(string $poNumber, string $field = 'outward'): void
    {
        $po = $this->findPurchaseOrder($poNumber);
        $this->poSuggestions = [];

        if (! $po) {
            $this->addError($field === 'inward' ? 'invoiceNumber' : 'poNumber', 'That PO is no longer available. Search again or use Fetch.');

            return;
        }

        if ($field === 'inward') {
            $this->invoiceNumber = (string) $po->po_number;
            $this->fetchBillDetails();

            return;
        }

        $this->poNumber = (string) $po->po_number;
        $this->clearFetchedPoData();
        $this->poNumber = (string) $po->po_number;
        $this->applyPurchaseOrder($po, 'PO Master selected from suggestions');
    }

    /** Clear fields controlled by the previous PO before resolving another one. */
    private function clearFetchedPoData(): void
    {
        $this->resetErrorBag('poNumber');
        $this->vendorName = '';
        $this->vendorGst = '';
        $this->poBillDate = '';
        $this->invoiceNumber = '';
        $this->selectedInvoiceNumber = '';
        $this->invoiceQty = '';
        $this->invoiceAmount = '';
        $this->rate = '';
        $this->material = '';
        $this->productLines = [];
        $this->availableInvoices = [];
        $this->poSuggestions = [];
        $this->fetched = false;
        $this->fetchedSource = '';
    }

    public function fetchPurchaseOrderDetails(): void
    {
        $value = trim($this->poNumber);
        $this->clearFetchedPoData();
        $this->poNumber = $value;
        if ($value === '') {
            $this->addError('poNumber', 'Enter a PO number first.');

            return;
        }

        $po = $this->findPurchaseOrder($value);
        if (! $po) {
            $this->addError('poNumber', 'PO not found in the local PO Master.');

            return;
        }

        $this->applyPurchaseOrder($po);
    }

    public function updatedSelectedInvoiceNumber(?string $value): void
    {
        if (! $value) {
            return;
        }

        $submission = VendorSubmission::where('invoice_number', $value)->latest()->first();
        if ($submission) {
            $this->applySubmission($submission, 'vendor submission selected from PO');
        }
    }

    /** A Guard may paste the PO directly into the inward lookup field. */
    public function updatedInvoiceNumber(?string $value): void
    {
        if ($this->entryType !== 'inward') {
            return;
        }

        $this->invoiceNumber = trim((string) $value);
        $this->refreshPoSuggestions($this->invoiceNumber);
    }

    /** Inward only — a bill number or PO number resolves the vendor's own
     * submission. When a PO has split billing, every available invoice is
     * returned and the latest bill is selected automatically, so the Guard
     * never retypes PO, vendor, quantity, date, amount or material. */
    public function fetchBillDetails(): void
    {
        $this->resetErrorBag('invoiceNumber');
        $this->validateOnly('invoiceNumber', ['invoiceNumber' => ['required', 'string']]);
        $this->invoiceNumber = trim($this->invoiceNumber);
        $lookupValue = $this->invoiceNumber;
        $this->clearFetchedPoData();
        $this->invoiceNumber = $lookupValue;

        $submission = VendorSubmission::where('invoice_number', $this->invoiceNumber)->latest()->first();

        if ($submission) {
            $this->applySubmission($submission);

            return;
        }

        // The guard often has the PO number itself in hand (printed on the
        // vendor's own paperwork), not just an invoice number — check our
        // own PO Master before applying the local validation rules.
        $localPo = $this->findPurchaseOrder($this->invoiceNumber);

        if ($localPo) {
            $submissions = VendorSubmission::where('po_number', $localPo->po_number)
                ->latest('invoice_date')
                ->latest('created_at')
                ->get();

            if ($submissions->isNotEmpty()) {
                $this->availableInvoices = $submissions->map(fn (VendorSubmission $row) => [
                    'invoice_number' => $row->invoice_number,
                    'invoice_date' => $row->invoice_date?->format('d M Y'),
                    'quantity' => $row->invoice_qty,
                    'amount' => $row->invoice_amount,
                ])->values()->all();
                $this->applySubmission($submissions->first(), 'vendor submission selected from PO');

                return;
            }

            $this->applyPurchaseOrder($localPo);
            // No vendor bill exists yet. Do not invent an invoice number;
            // the gate remains held for the real physical bill number.
            $this->invoiceNumber = '';
            $this->selectedInvoiceNumber = '';
            $this->fetchedSource = 'PO Master';
            $this->fetched = true;

            return;
        }

        $this->fetched = false;
        $this->addError('invoiceNumber', 'No local vendor submission or PO found for this number. Create/release the PO locally or ask the vendor to submit the dispatch documents first.');
    }

    public function fillSample(): void
    {
        $this->ocrReady = true;
        $this->location = 'bhiwandi';

        if ($this->entryType === 'visitor') {
            $this->billScanned = false;
            $this->visitorHostId = (string) (User::where('role', Role::StoreManager)->value('id') ?? '');
            $this->vendorName = 'Tesmed Service Partner';
            $this->invoiceNumber = 'VIS-'.now()->format('ymdHis');
            $this->invoiceQty = '1';
            $this->visitPurpose = 'Service / Maintenance Visit';
            $this->transporter = 'Service visit';
            $this->vehicleNumber = 'WALK-IN';
            $this->visitorName = 'Amit Sharma';
            $this->driverPhone = '+91 98111 22334';
            $this->documents = ['invoice' => false, 'eway' => false, 'lr' => false, 'pod' => false];
            $this->productLines = [];
        } elseif ($this->entryType === 'outward') {
            $this->billScanned = true;
            $this->poNumber = 'OUT RM 2627 0020';
            $this->vendorName = 'DELHI-TAN90-MU Dispatch';
            $this->vendorGst = '07AACCT9090K1Z2';
            $this->invoiceNumber = 'OUT/INV/'.now()->format('His').random_int(10, 99);
            $this->invoiceQty = '180';
            $this->rate = '42';
            $this->material = 'PCM Raw Compound (TN-1 Grade)';
            $this->transporter = 'VRL Surface Logistics';
            $this->vehicleNumber = 'DL 01 AB '.random_int(1000, 9999);
            $this->driverName = 'Rakesh Yadav';
            $this->driverPhone = '+91 92056 54865';
            $this->documents = ['invoice' => true, 'eway' => true, 'lr' => true, 'pod' => false];
            $this->productLines = [['sku' => $this->material, 'name' => $this->material, 'qty' => $this->invoiceQty, 'uom' => 'KG', 'remarks' => 'Outward dispatch']];
        } else {
            $this->billScanned = true;
            $this->poNumber = 'PO RM 2627 0020';
            $this->poBillDate = now()->format('Y-m-d');
            $this->vendorName = 'Thermocol Material Private Ltd';
            $this->vendorGst = '27AACCH1234K1Z5';
            $this->invoiceNumber = 'TCM/INV/'.now()->format('His').random_int(10, 99);
            $this->invoiceQty = '700';
            $this->invoiceAmount = '29400';
            $this->rate = '42';
            $this->material = 'PCM Raw Compound (TN-1 Grade)';
            $this->transporter = 'Blue Dart Surface Logistics';
            $this->vehicleNumber = 'MH 04 GT '.random_int(1000, 9999);
            $this->driverName = 'Himanshu Kumar';
            $this->driverPhone = '+91 98765 43210';
            $this->documents = ['invoice' => true, 'eway' => true, 'lr' => true, 'pod' => true];
            $this->productLines = [['sku' => $this->material, 'name' => $this->material, 'qty' => $this->invoiceQty, 'uom' => 'KG', 'remarks' => 'PO-linked inward receipt']];
            $this->fetched = true;
        }

        $this->useGps();
    }

    public function saveEntry(): void
    {
        if (! $this->syncOutwardShipmentTotals()) {
            return;
        }

        $rules = match ($this->entryType) {
            'visitor' => ['visitorName' => ['required', 'string'], 'driverPhone' => ['required', 'string'], 'visitorHostId' => ['required', 'integer', 'exists:users,id'], 'visitPurpose' => ['required', 'string']],
            'inward' => ['driverName' => ['required', 'string'], 'driverPhone' => ['required', 'string'], 'vehicleNumber' => ['required', 'string'], 'invoiceNumber' => ['required', 'string'], 'invoiceAmount' => ['required', 'numeric'], 'poNumber' => ['nullable', 'string'], 'vendorName' => ['required', 'string'], 'material' => ['required', 'string']],
            default => $this->isQuickTruckIn()
                ? ['vehicleNumber' => ['required', 'string'], 'driverName' => ['required', 'string'], 'contactUserId' => ['required', 'integer', 'exists:users,id']]
                : ['vehicleNumber' => ['required', 'string'], 'driverName' => ['required', 'string'], 'poNumber' => ['required', 'string'], 'vendorName' => ['required', 'string'], 'invoiceNumber' => ['required', 'string'], 'material' => ['required', 'string'], 'invoiceQty' => ['required', 'integer', 'min:1']]
        };
        $rules['contactUserId'] ??= ['nullable', 'integer', 'exists:users,id'];
        if ($this->entryType === 'visitor') {
            unset($rules['contactUserId']);
        }
        $this->validate($rules, ['contactUserId.required' => 'Pick whom the driver has come to meet.']);
        if ($this->isQuickTruckIn()) {
            // Only driver, vehicle and contact at the gate; the Store Manager links
            // the Outward Shipment when assigning the dock.
            $this->poNumber = 'Truck for loading';
            $this->vendorName = 'Outward pickup';
            $this->invoiceNumber = 'TBD';
            $this->material = 'To be loaded';
            $this->invoiceQty = '1';
        }

        // Deliberately NOT a hard block on !fetched: a guard must still be able
        // to log a truck whose PO genuinely isn't in the system yet (new
        // vendor, PO not entered/synced yet). Unknown PO references remain a
        // hard stop; an intentionally unlinked receipt is saved for review.

        if (! $this->gps) {
            $this->useGps();
        }

        $isVisitor = $this->entryType === 'visitor';
        // Visitor uses its own dedicated fields (visitorName/visitorHostId/
        // visitPurpose) rather than reusing driverName/poNumber/material --
        // still maps onto the same gate_entries columns as inward/outward,
        // since it's one shared table, just sourced from the right property.
        // personToMeet used to be free text (anyone could type any name,
        // with no actual approval routing) -- it's now a real user, picked
        // from a dropdown, so the visit can be routed to that person for
        // approval before Guard lets them in.
        $hostUser = $isVisitor ? User::find($this->visitorHostId) : null;
        $driverName = $isVisitor ? $this->visitorName : $this->driverName;
        $poNumber = $isVisitor ? ($hostUser?->name ?? '') : (trim($this->poNumber) !== '' ? trim($this->poNumber) : null);
        $material = $isVisitor ? $this->visitPurpose : $this->material;

        $this->updatedLocation($this->location); // a site's Guard always saves for that site
        $selected = self::LOCATIONS[$this->location] ?? ['name' => $this->location, 'code' => 'NA'];
        $qty = $this->invoiceQty !== '' ? $this->invoiceQty : '1';
        $form = [
            'entry_type' => $this->entryType,
            'po_number' => $poNumber,
            'po_bill_date' => $this->poBillDate ?: null,
            'vendor_name' => $this->vendorName ?: ($isVisitor ? ucfirst($this->entryType) : null),
            'vendor_gst' => $this->vendorGst ?: null,
            'invoice_number' => $this->invoiceNumber ?: ($isVisitor ? strtoupper(substr($this->entryType, 0, 3)).'-'.now()->format('ymdHis') : null),
            'invoice_qty' => $qty,
            'invoice_amount' => $this->invoiceAmount !== '' ? $this->invoiceAmount : null,
            'rate' => $this->rate !== '' ? $this->rate : null,
            'material' => $material ?: 'N/A',
            'product_lines' => collect($this->productLines)
                ->filter(fn ($line) => trim((string) ($line['sku'] ?? '')) !== '')
                ->map(fn ($line) => [
                    'po_line_id' => is_numeric($line['po_line_id'] ?? null) ? (int) $line['po_line_id'] : null,
                    'sku' => trim((string) $line['sku']),
                    'name' => trim((string) ($line['name'] ?? $line['sku'])),
                    'qty' => is_numeric($line['qty'] ?? null) ? (float) $line['qty'] : null,
                    'uom' => trim((string) ($line['uom'] ?? '')),
                    'unit_price' => is_numeric($line['unit_price'] ?? null) ? (float) $line['unit_price'] : null,
                    'remarks' => trim((string) ($line['remarks'] ?? '')),
                ])->values()->all(),
            'outward_shipment_id' => $this->entryType === 'outward' ? $this->outwardShipmentId : null,
            'vehicle_number' => $this->vehicleNumber ?: 'WALK-IN',
            'driver_name' => $driverName,
            'contact_user_id' => ! $isVisitor && $this->contactUserId !== '' ? (int) $this->contactUserId : null,
            'transporter' => $this->transporter ?: null,
            'location' => $selected['name'],
            'gps' => $this->gps ?: null,
        ];

        $issues = app(GateValidationService::class)->validate($form);
        $poSiteIssue = collect($issues)->firstWhere('code', 'PO_SITE_MISMATCH');
        if ($poSiteIssue) {
            $this->addError('poNumber', $poSiteIssue['description']);

            return;
        }
        $blocking = app(GateValidationService::class)->isBlocking($issues);
        // Every inward, outward AND visitor entry now waits for an explicit
        // approval before it can move on -- inward/outward go to Store
        // Manager (Entry Approvals), visitor goes to whichever person the
        // visitor is here to see (Visitor Approvals). Previously a clean
        // entry (no hardFail/redFlag issues) skipped straight to
        // "validated" here with no human ever reviewing it -- for visitor
        // that meant anyone typing any name got waved straight in.
        $needsApproval = in_array($this->entryType, ['inward', 'outward', 'visitor'], true);
        $vendorUser = $form['vendor_name'] ? User::where('role', Role::Vendor)->where('name', $form['vendor_name'])->first() : null;
        if ($form['outward_shipment_id'] && ! \App\Models\Outward\OutwardShipment::whereKey($form['outward_shipment_id'])->whereIn('status', ['documents_pending', 'ready'])->whereNull('gate_entry_id')->exists()) {
            $this->addError('outwardShipmentId', 'That shipment already has a truck or is no longer open.');

            return;
        }
        $documentPath = $this->billFile ? $this->billFile->store('gate-bills') : null;
        $gate = GateEntry::create([
            ...$form,
            'created_by' => auth()->id(),
            'visitor_host_id' => $hostUser?->id,
            'gate_no' => $this->nextGateNumber(),
            'bill_scanned' => $isVisitor ? false : $this->billScanned,
            'bill_document_path' => $documentPath,
            'remarks' => trim($this->remarks."\nDocuments: ".$this->documentSummary()."\nLine: ".$material.' x '.$qty) ?: null,
            'status' => ($needsApproval || $blocking) ? 'pending_validation' : 'validated',
            'sla_deadline' => now()->addHours(SlaDirectives::hours($vendorUser?->sla_directive)),
        ]);

        foreach ($issues as $issue) {
            ValidationIssue::create([...$issue, 'gate_entry_id' => $gate->id, 'status' => 'open']);
        }
        if ($gate->outward_shipment_id) {
            app(\App\Services\Outward\OutwardShipmentService::class)->linkGate(\App\Models\Outward\OutwardShipment::find($gate->outward_shipment_id), $gate);
        }

        if (in_array($gate->entry_type, ['inward', 'outward'], true)) {
            app(\App\Services\OperationalEventMailer::class)->notify(
                ucfirst($gate->entry_type)." gate entry {$gate->gate_no} needs approval",
                "A {$gate->entry_type} gate entry was created for {$gate->vendor_name} at {$gate->location}. Store Manager approval is the next step.",
                $gate->location_id ?: \App\Support\SiteLocation::idForName($gate->location),
                [\App\Enums\Role::StoreManager],
                '/entry-approvals',
            );
        }

        AuditLogger::log($this->modeLabel().' entry created', "{$gate->gate_no} - {$selected['code']} - {$gate->vendor_name}", $gate);
        $this->saved = ['gate' => $gate, 'issues' => $issues, 'location' => $selected];
        session()->flash('success', "{$gate->gate_no} saved and sent for validation.");
    }

    private function isQuickTruckIn(): bool
    {
        return $this->entryType === 'outward' && ! $this->outwardFullForm && ! $this->outwardShipmentId && trim($this->poNumber) === '';
    }

    public function resetForm(): void
    {
        $currentType = $this->entryType;
        $this->outwardFullForm = false;
        $this->contactUserId = '';
        $this->billScanned = false;
        $this->billFile = null;
        $this->ocrReady = false;
        $this->gps = '';
        $this->saved = null;
        $this->fetched = false;
        $this->outwardShipmentId = null;
        $this->fetchedSource = '';
        $this->availableInvoices = [];
        $this->selectedInvoiceNumber = '';
        $this->poNumber = '';
        $this->poBillDate = '';
        $this->vendorName = '';
        $this->vendorGst = '';
        $this->invoiceNumber = '';
        $this->invoiceQty = '';
        $this->invoiceAmount = '';
        $this->rate = '';
        $this->material = '';
        $this->transporter = '';
        $this->vehicleNumber = '';
        $this->driverName = '';
        $this->driverPhone = '';
        $this->visitorName = '';
        $this->visitorHostId = '';
        $this->visitPurpose = '';
        $this->remarks = '';
        $this->location = 'bhiwandi';
        $this->documents = ['invoice' => false, 'eway' => false, 'lr' => false, 'pod' => false];
        $this->productLines = [];
        $this->entryType = $currentType;
    }

    private function documentSummary(): string
    {
        if ($this->entryType === 'visitor') {
            return 'Visitor pass only';
        }

        $labels = ['invoice' => 'Invoice', 'eway' => 'E-way Bill', 'lr' => 'LR/LRC', 'pod' => 'POD'];

        // Confirmed live: this read as "no documents checked" full stop,
        // even when the vendor had already uploaded documents digitally via
        // their portal beforehand -- this is only about what Guard
        // physically saw handed over at the gate, a separate thing.
        return collect($this->documents)->filter()->keys()->map(fn ($key) => $labels[$key] ?? $key)->join(', ') ?: 'No physical documents checked at gate';
    }

    private function modeLabel(): string
    {
        return match ($this->entryType) {
            'outward' => 'Outward',
            'visitor' => 'Visitor',
            default => 'Inward',
        };
    }
}; ?>

<div class="space-y-5">
    @if ($saved)
        <section class="rounded-2xl border p-6 text-center" style="background: var(--surface-3); border-color: var(--border);">
            <div class="mx-auto w-14 h-14 rounded-2xl grid place-items-center text-xl font-black" style="background: var(--status-good-bg); color: var(--status-good);">OK</div>
            <h1 class="text-2xl font-bold mt-4" style="color: var(--text-primary);">Gate Pass Created Successfully</h1>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $saved['gate']->gate_no }} - {{ $saved['location']['code'] }} · {{ \App\Support\GateStatusLabels::label($saved['gate']->status) }}</p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-5 text-left">
                <div class="rounded-xl border p-4" style="border-color: var(--border); background: var(--surface-2);">
                    <div class="text-xs uppercase" style="color: var(--text-muted);">{{ ucfirst($saved['gate']->entry_type) }}</div>
                    <div class="font-semibold mt-1" style="color: var(--text-primary);">{{ $saved['gate']->vendor_name }}</div>
                    <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $saved['gate']->po_number }}</div>
                </div>
                <div class="rounded-xl border p-4" style="border-color: var(--border); background: var(--surface-2);">
                    <div class="text-xs uppercase" style="color: var(--text-muted);">Person / Vehicle</div>
                    <div class="font-semibold mt-1" style="color: var(--text-primary);">{{ $saved['gate']->driver_name }}</div>
                    <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $saved['gate']->vehicle_number }}</div>
                </div>
                <div class="rounded-xl border p-4" style="border-color: var(--border); background: var(--surface-2);">
                    <div class="text-xs uppercase" style="color: var(--text-muted);">Next Step</div>
                    <div class="font-semibold mt-1" style="color: var(--text-primary);">
                        @if (in_array($saved['gate']->entry_type, ['inward', 'outward'], true))
                            Awaiting Store Manager approval
                        @elseif ($saved['gate']->entry_type === 'visitor')
                            Awaiting {{ $saved['gate']->po_number ?: "the host's" }} approval
                        @else
                            Gate pass active
                        @endif
                    </div>
                    <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ count($saved['issues']) ? count($saved['issues']).' issue(s) to review' : 'No blocking issue' }}</div>
                </div>
            </div>

            @if (count($saved['issues']))
                <div class="rounded-xl p-4 mt-5 text-left text-sm" style="background: var(--status-critical-bg); color: var(--status-critical);">
                    <div class="font-semibold mb-2">Flags saved with this entry</div>
                    @foreach ($saved['issues'] as $issue)
                        <div><strong>{{ $issue['title'] }}</strong> - {{ $issue['description'] }}</div>
                    @endforeach
                </div>
            @else
                <div class="rounded-xl p-4 mt-5 text-sm" style="background: var(--status-good-bg); color: var(--status-good);">Saved successfully. No blocking issue found.</div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-5">
                <button wire:click="resetForm" class="rounded-xl px-4 py-3 text-sm font-semibold border" style="border-color: var(--border); color: var(--text-primary);">New {{ strtolower($modeLabel) }}</button>
                {{-- Was linking straight to /unloading (Store Exec-only route) even for the
                     Guard, who has no access to it -- a hard 403 dead-end with no way back.
                     The entry is already validated and waiting for Store Exec the moment
                     it's saved; nothing more for the guard to do here but view it. --}}
                <a href="{{ route('guard.entries.show', $saved['gate']) }}" wire:navigate class="rounded-xl px-4 py-3 text-sm font-semibold text-white text-center" style="background: var(--brand);">View Entry</a>
                <a href="{{ route('guard.entries') }}" wire:navigate class="rounded-xl px-4 py-3 text-sm font-semibold border text-center" style="border-color: var(--border); color: var(--text-primary);">Guard Entries</a>
                @if (in_array($saved['gate']->entry_type, ['inward', 'outward'], true))
                    <a href="{{ route('store-manager.entry-approvals') }}" wire:navigate class="rounded-xl px-4 py-3 text-sm font-semibold border text-center" style="border-color: var(--border); color: var(--text-primary);">Approval queue</a>
                @endif
            </div>
        </section>
    @else
        <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Guard Module</div>
                    <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">{{ $modeLabel }} Entry</h1>
                    <p class="text-sm mt-1" style="color: var(--text-secondary);">Simple gate form. Select type, quick fill or enter details, capture GPS, then save.</p>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    @foreach (['inward' => 'Inward', 'outward' => 'Outward', 'visitor' => 'Visitor'] as $value => $label)
                        <button wire:click="setEntryType('{{ $value }}')" class="rounded-xl px-4 py-3 text-sm font-semibold border" style="{{ $entryType === $value ? 'background: var(--brand); color: white; border-color: var(--brand);' : 'border-color: var(--border); color: var(--text-primary);' }}">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            @if ($entryType === 'outward')
                <div class="grid grid-cols-2 gap-2 mt-4" role="group" aria-label="Outward: truck in or out">
                    <button type="button" wire:click="setOutwardMode('in')" class="rounded-xl px-4 py-3 text-sm font-semibold border text-left" style="{{ $outwardMode === 'in' ? 'background: var(--brand-bg); border-color: var(--brand); color: var(--brand);' : 'border-color: var(--border); color: var(--text-primary);' }}">Truck In<span class="block text-xs font-normal" style="color: var(--text-secondary);">Truck arriving to load - driver, vehicle, whom they meet</span></button>
                    <button type="button" wire:click="setOutwardMode('out')" class="rounded-xl px-4 py-3 text-sm font-semibold border text-left" style="{{ $outwardMode === 'out' ? 'background: var(--brand-bg); border-color: var(--brand); color: var(--brand);' : 'border-color: var(--border); color: var(--text-primary);' }}">Truck Out<span class="block text-xs font-normal" style="color: var(--text-secondary);">Loaded truck leaving - see what goes out, confirm exit</span></button>
                </div>
            @endif
            <nav aria-label="Guard entry shortcuts" class="flex flex-wrap gap-2 mt-5 pt-4 border-t" style="border-color: var(--border);">
                <a href="{{ route('guard.scan') }}" wire:navigate class="min-h-10 inline-flex items-center rounded-lg px-3 py-2 text-xs font-semibold" style="background: var(--brand-bg); color: var(--brand);">New gate entry</a>
                <a href="{{ route('guard.entries', ['type' => 'inward']) }}" wire:navigate class="min-h-10 inline-flex items-center rounded-lg px-3 py-2 text-xs font-semibold border" style="border-color: var(--border); color: var(--brand);">Recent inward</a>
                <a href="{{ route('guard.entries', ['type' => 'outward']) }}" wire:navigate class="min-h-10 inline-flex items-center rounded-lg px-3 py-2 text-xs font-semibold border" style="border-color: var(--border); color: var(--status-warning);">Recent outward</a>
                <a href="{{ route('guard.entries', ['type' => 'visitor']) }}" wire:navigate class="min-h-10 inline-flex items-center rounded-lg px-3 py-2 text-xs font-semibold border" style="border-color: var(--border); color: var(--text-secondary);">Recent visitors</a>
            </nav>
        </section>


        @if ($entryType === 'outward' && $outwardMode === 'out')
        <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Trucks waiting to leave</h2>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">Check what is on the truck, then confirm the exit. For a truck carrying an Outward Shipment, confirming the exit dispatches it and the stock goes down.</p>
            @if (session('success'))<div role="status" class="rounded-xl border px-4 py-3 mt-3 text-sm font-medium" style="background: var(--status-good-bg); border-color: var(--status-good); color: var(--status-good);">{{ session('success') }}</div>@endif
            <div class="space-y-3 mt-4">
                @forelse ($leavingTrucks as $truck)
                    @php($ship = $truck->outwardShipment)
                    <div wire:key="leaving-{{ $truck->id }}" class="rounded-xl border p-4" style="border-color: {{ $truck->status === 'loaded' ? 'var(--status-good)' : 'var(--border)' }}; background: var(--surface-1);">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="text-sm">
                                <div class="font-bold" style="color: var(--text-primary);">{{ $truck->gate_no }} · {{ $truck->vehicle_number }} · {{ $truck->driver_name }}</div>
                                <div class="text-xs mt-0.5" style="color: var(--text-secondary);">
                                    {{ $truck->status === 'loaded' ? 'Loaded - ready to leave' : 'Not loaded yet ('.str_replace('_', ' ', $truck->status).')' }}{{ $truck->loading_dock ? ' · Dock '.$truck->loading_dock : '' }}
                                    @if ($truck->contactUser) · Meeting {{ $truck->contactUser->name }}{{ $truck->contactUser->phone ? ' ('.$truck->contactUser->phone.')' : '' }} @endif
                                </div>
                            </div>
                            @if ($truck->status === 'loaded')
                                <button type="button" wire:click="confirmTruckExit({{ $truck->id }})" wire:confirm="Let {{ $truck->vehicle_number }} leave and dispatch what is on it?" class="rounded-lg px-4 py-2 text-sm font-bold text-white" style="background: var(--brand);">Confirm exit &amp; dispatch</button>
                            @endif
                        </div>
                        @if ($ship)
                            <div class="mt-3 rounded-lg border p-3 text-xs" style="border-color: var(--border);">
                                <div class="font-semibold" style="color: var(--text-primary);">{{ $ship->number }} · {{ $ship->documentLabel() }} {{ $ship->invoice_number ?: $ship->challan_number }} · {{ $ship->from_name }} → {{ $ship->to_name }}</div>
                                @if ($ship->ewb_number)<div style="color: var(--text-secondary);">E-Way Bill {{ $ship->ewb_number }}</div>@endif
                                <ul class="mt-1.5 space-y-0.5" style="color: var(--text-primary);">
                                    @foreach ($ship->lines as $line)
                                        <li>{{ rtrim(rtrim((string) $line->qty, '0'), '.') }} {{ $line->item?->unit }} · {{ $line->item?->displayName() }}</li>
                                    @endforeach
                                </ul>
                                @if ($ship->status === 'documents_pending')<div class="mt-1 font-semibold" style="color: var(--status-warning);">Documents still pending - the truck cannot leave until they are uploaded.</div>@endif
                            </div>
                        @elseif ($truck->material && $truck->material !== 'To be loaded')
                            <div class="mt-2 text-xs" style="color: var(--text-secondary);">{{ $truck->material }} × {{ $truck->invoice_qty }} · {{ $truck->vendor_name }}</div>
                        @else
                            <div class="mt-2 text-xs" style="color: var(--text-muted);">No Outward Shipment linked yet - the Store Manager links it when assigning the dock.</div>
                        @endif
                        @error('exit.'.$truck->id) <div class="mt-2 text-xs font-semibold" style="color: var(--status-critical);">{{ $message }}</div> @enderror
                    </div>
                @empty
                    <p class="text-sm" style="color: var(--text-muted);">No outward trucks inside right now.</p>
                @endforelse
            </div>
        </section>
        @else
        <div class="grid grid-cols-1 xl:grid-cols-[1fr_340px] gap-5">
            <section class="rounded-2xl border p-5" x-data="{ scanning: false, scanMessage: '' }" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex flex-col md:flex-row gap-3 md:items-center md:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold" style="color: var(--text-primary);">{{ $entryType === 'visitor' ? 'Visitor Pass' : 'Scan / Autofill' }}</h2>
                        <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $entryType === 'visitor' ? 'No bill upload for visitor entry.' : 'Use quick scan, or upload/capture bill if needed.' }}</p>
                        <p class="text-xs mt-2 rounded-lg border px-3 py-2" style="border-color: var(--brand); background: var(--brand-bg); color: var(--text-primary);">
                            @if ($entryType === 'visitor')
                                Automatic validation: the pass is routed to the selected host for approval before entry is allowed.
                            @elseif ($entryType === 'outward')
                                Automatic validation: the package/PO is checked when fetched, then the entry waits for Store Manager approval and dock assignment.
                            @else
                                Automatic validation: the PO and vendor details are checked before the entry is sent for Store Manager approval and dock assignment.
                            @endif
                        </p>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <button type="button" wire:click="fillSample" class="rounded-xl px-4 py-3 text-sm font-bold text-white" style="background: var(--brand);">{{ $entryType === 'visitor' ? 'Quick Visitor Fill' : 'Quick Scan Autofill' }}</button>
                        @unless ($entryType === 'visitor')
                            <label class="rounded-xl px-4 py-3 text-sm font-semibold border cursor-pointer text-center" style="border-color: var(--border); color: var(--text-primary);">
                                <span x-show="!scanning" wire:loading.remove wire:target="billFile">{{ $billFile ? 'Change File' : 'Camera / Upload' }}</span>
                                <span wire:loading wire:target="billFile">Uploading...</span>
                                <span x-show="scanning" x-cloak>Reading document…</span>
                                <input
                                    type="file"
                                    accept="image/*,.pdf"
                                    capture="environment"
                                    class="hidden"
                                    wire:model="billFile"
                                    x-on:change="
                                        const file = $event.target.files[0];
                                        const isInward = {{ $entryType === 'inward' ? 'true' : 'false' }};
                                        if (! file || ! isInward) { return; }
                                        scanning = true;
                                        scanMessage = '';
                                        window.tan90ScanBillForPoNumber(file).then((po) => {
                                            scanning = false;
                                            if (po) {
                                                scanMessage = 'Found PO ' + po + ' on the document — fetching matching details…';
                                                $wire.invoiceNumber = po;
                                                $wire.fetchBillDetails();
                                            } else {
                                                scanMessage = 'Could not automatically read a PO number from this document — enter it below.';
                                            }
                                        });
                                    "
                                />
                            </label>
                        @endunless
                    </div>
                </div>

                <div x-show="scanMessage" x-cloak class="text-xs mt-2" style="color: var(--text-secondary);" x-text="scanMessage"></div>

                @unless ($entryType === 'visitor')
                    @error('billFile') <p class="text-xs mt-2" style="color: var(--status-critical);">{{ $message }}</p> @enderror
                    @if ($billFile)
                        <div class="rounded-xl border p-3 mt-2 text-xs" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--text-primary);">
                            Attached: {{ $billFile->getClientOriginalName() }}.
                            {{ $entryType === 'inward' ? ' Scanned automatically for a PO number where possible — double-check the fetched details below before saving.' : ' Fill in the details below and save.' }}
                        </div>
                    @endif
                @endunless

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-5">
                    @if ($entryType === 'visitor')
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Visitor Name</span>
                            <input wire:model="visitorName" id="visitorName" name="visitorName" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Visitor name" />
                            @error('visitorName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Mobile</span>
                            <input wire:model="driverPhone" id="driverPhone" name="driverPhone" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="+91 ..." />
                            @error('driverPhone') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Company</span>
                            <input wire:model="vendorName" id="vendorName" name="vendorName" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Company / agency" />
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Person To Meet</span>
                            <select wire:model="visitorHostId" id="visitorHostId" name="visitorHostId" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border); background: var(--surface-1); color: var(--text-primary);">
                                <option value="">Select who they're here to see…</option>
                                @foreach ($hostOptions as $host)
                                    <option value="{{ $host->id }}">{{ $host->name }} ({{ $host->role?->label() ?? $host->role?->value }})</option>
                                @endforeach
                            </select>
                            <span class="text-xs" style="color: var(--text-muted);">The visit is sent to this person for approval before entry is allowed.</span>
                            @error('visitorHostId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm md:col-span-2">
                            <span class="font-semibold" style="color: var(--text-primary);">Purpose</span>
                            <select wire:model="visitPurpose" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border); background: var(--surface-1); color: var(--text-primary);">
                                <option value="">Select a reason…</option>
                                @foreach ($visitPurposeOptions as $option)
                                    <option value="{{ $option }}">{{ $option }}</option>
                                @endforeach
                            </select>
                            @error('visitPurpose') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                    @elseif ($entryType === 'inward')
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Driver Name</span>
                            <input wire:model="driverName" id="driverName" name="driverName" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Driver name" />
                            @error('driverName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Driver Phone Number</span>
                            <input wire:model="driverPhone" id="driverPhone" name="driverPhone" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="+91 ..." />
                            @error('driverPhone') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm md:col-span-2">
                            <span class="font-semibold" style="color: var(--text-primary);">Vehicle Number</span>
                            <input wire:model="vehicleNumber" id="vehicleNumber" name="vehicleNumber" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="MH 04 GT 5521" />
                            @error('vehicleNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm md:col-span-2">
                            <span class="font-semibold" style="color: var(--text-primary);">Meeting whom{{ $entryType === 'inward' ? ' (optional)' : '' }}</span>
                            <select wire:model.live="contactUserId" id="contactUserId" name="contactUserId" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border); background: var(--surface-1); color: var(--text-primary);">
                                <option value="">Whom has the driver come to meet?</option>
                                @foreach ($hostOptions as $host)
                                    <option value="{{ $host->id }}">{{ $host->name }} ({{ $host->role?->label() ?? $host->role?->value }}){{ $host->phone ? ' · '.$host->phone : '' }}</option>
                                @endforeach
                            </select>
                            @if ($contactUserId !== '' && ($contact = $hostOptions->firstWhere('id', (int) $contactUserId)) && $contact->phone)
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact->phone) }}" class="text-xs font-semibold underline" style="color: var(--brand);">Call {{ $contact->name }}: {{ $contact->phone }}</a>
                            @endif
                            @error('contactUserId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>

                        <label class="space-y-1.5 text-sm md:col-span-2">
                            <span class="font-semibold" style="color: var(--text-primary);">Invoice / PO Number</span>
                            <div class="flex gap-2">
                                <div class="relative flex-1">
                                    <input wire:model.live.debounce.250ms="invoiceNumber" id="invoiceNumber" name="invoiceNumber" autocomplete="off" aria-autocomplete="list" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Bill number or PO number" />
                                    @if ($poSuggestions)
                                        <div role="listbox" class="absolute z-30 top-full left-0 right-0 mt-1 rounded-xl border shadow-lg overflow-hidden" style="border-color: var(--border); background: var(--surface-1);">
                                            @foreach ($poSuggestions as $suggestion)
                                                <button type="button" wire:click="selectPoSuggestion(@js($suggestion['po_number']), 'inward')" class="w-full text-left px-3 py-2.5 transition-colors hover:bg-black/5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px]" role="option">
                                                    <span class="block text-sm font-semibold" style="color: var(--text-primary);">{{ $suggestion['po_number'] }}</span>
                                                    <span class="block text-xs mt-0.5" style="color: var(--text-muted);">{{ $suggestion['vendor_name'] }} · {{ $suggestion['status'] }} · Select to fetch</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                <button type="button" wire:click="fetchBillDetails" wire:loading.attr="disabled" wire:target="fetchBillDetails" class="shrink-0 rounded-xl px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50" style="background: var(--brand);"><span wire:loading.remove wire:target="fetchBillDetails">Fetch</span><span wire:loading wire:target="fetchBillDetails">Fetching…</span></button>
                            </div>
                            @error('invoiceNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            @if (count($availableInvoices))
                                <div class="mt-2 rounded-xl border p-3" style="border-color: var(--brand); background: var(--brand-bg);">
                                    <label class="text-xs font-semibold" style="color: var(--text-primary);">Matched vendor bills for {{ $poNumber }}</label>
                                    <select wire:model.live="selectedInvoiceNumber" class="mt-1.5 w-full rounded-lg border px-3 py-2.5 text-sm" style="border-color: var(--border); background: var(--surface-1);">
                                        @foreach ($availableInvoices as $invoice)
                                            <option value="{{ $invoice['invoice_number'] }}">{{ $invoice['invoice_number'] }} · {{ $invoice['invoice_date'] ?? 'No date' }} · Qty {{ $invoice['quantity'] }} · ₹{{ number_format((float) ($invoice['amount'] ?? 0), 2) }}</option>
                                        @endforeach
                                    </select>
                                    <div class="text-xs mt-1.5" style="color: var(--text-secondary);">PO lookup selected the latest available bill automatically. Change it here when the vehicle carries another split invoice.</div>
                                </div>
                            @endif
                        </label>

                        @if ($fetched)
                            <div class="md:col-span-2 rounded-xl border p-3 text-xs" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--text-primary);">
                                @if ($fetchedSource)
                                    <div>Source: {{ $fetchedSource }}</div>
                                @endif
                                Matched — {{ $poNumber }} · {{ $vendorName }} · Qty {{ $invoiceQty }} · {{ $material }}
                                @if ($fetchedSource === 'PO Master')
                                    <div class="mt-1 font-semibold">This PO has no vendor bill submission yet. The physical invoice number is still required before saving.</div>
                                @endif
                            </div>
                        @endif

                        @if ($productLines && $entryType !== 'visitor')
                            <div class="md:col-span-2 rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2);">
                                <div class="text-xs font-semibold mb-2" style="color: var(--text-primary);">PO / vendor invoice line details</div>
                                <div class="flex flex-col gap-2">
                                    @foreach ($productLines as $line)
                                        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs" wire:key="guard-po-line-{{ $loop->index }}">
                                            <span class="font-medium" style="color: var(--text-primary);">{{ $line['name'] ?? $line['sku'] }}</span>
                                            <span style="color: var(--text-secondary);">{{ $line['qty'] ?? '—' }} {{ $line['uom'] ?? '' }}{{ isset($line['unit_price']) && $line['unit_price'] !== null ? ' · ₹'.number_format((float) $line['unit_price'], 2).' / unit' : '' }}</span>
                                        </div>
                                        @if (! empty($line['remarks']) && str_contains((string) $line['remarks'], 'no stored per-SKU'))
                                            <div role="note" data-listing-ignore class="text-xs rounded-lg border p-2" style="border-color: var(--status-warning); color: var(--status-warning);">{{ $line['remarks'] }}</div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Resolved PO Number (optional)</span>
                            <input wire:model="poNumber" id="poNumber" name="poNumber" readonly aria-readonly="true" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5 cursor-not-allowed" style="border-color: var(--border); background: var(--surface-2);" placeholder="Select a PO from the lookup above" />
                            <span class="text-xs" style="color: var(--text-muted);">Select a suggestion or use Fetch above when a PO is available. Without a PO, save the invoice and mapped SKU details; the entry will be flagged for review.</span>
                            @error('poNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Vendor</span>
                            <input wire:model="vendorName" id="vendorName" name="vendorName" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Vendor" />
                            @error('vendorName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Bill Date</span>
                            <input wire:model="poBillDate" id="poBillDate" name="poBillDate" autocomplete="off" type="date" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" />
                            @error('poBillDate') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Bill Amount</span>
                            <input wire:model="invoiceAmount" id="invoiceAmount" name="invoiceAmount" autocomplete="off" type="number" step="0.01" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="29400" />
                            @error('invoiceAmount') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm md:col-span-2">
                            <span class="font-semibold" style="color: var(--text-primary);">Material / SKU</span>
                            <input wire:model="material" id="material" name="material" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="PCM Raw Compound (TN-1 Grade)" />
                            <span class="text-xs" style="color: var(--text-muted);">Autofilled from the vendor submission or PO line. Correct it here if the physical bill uses a different SKU.</span>
                            @error('material') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                    @else
                        <div class="md:col-span-2 rounded-xl border p-3 text-sm" style="border-color: var(--brand); background: var(--brand-bg);">
                            <div class="font-semibold" style="color: var(--brand);">Which Outward Shipment is this truck for?</div>
                            @if ($linkedShipment)
                                <div class="mt-1" style="color: var(--text-primary);">{{ $linkedShipment->number }} · {{ $linkedShipment->documentLabel() }} {{ $linkedShipment->invoice_number ?: $linkedShipment->challan_number }} → {{ $linkedShipment->to_name }}. Stock goes down when you confirm this truck's exit{{ $linkedShipment->status === 'documents_pending' ? ' (documents still pending - the truck cannot leave until they are uploaded)' : '' }}.</div>
                                <button type="button" wire:click="clearOutwardShipment" class="mt-1 text-xs font-semibold underline" style="color: var(--text-muted);">Not this one</button>
                            @elseif ($readyShipments->isNotEmpty())
                                <div class="text-xs mb-2" style="color: var(--text-secondary);">Pick the shipment this truck will carry - customer, items and document numbers fill in automatically. Not sure? Leave it - the Store Manager can pick it when assigning the dock.</div>
                                <div class="flex flex-col gap-1.5">
                                    @foreach ($readyShipments as $shipment)
                                        <button type="button" wire:key="ready-shipment-{{ $shipment->id }}" wire:click="selectOutwardShipment({{ $shipment->id }})" class="text-left rounded-lg border px-3 py-2 text-xs" style="border-color: var(--border); background: var(--surface-1);">
                                            <b>{{ $shipment->number }}</b>{{ $shipment->status === 'documents_pending' ? ' (documents pending)' : '' }} · {{ $shipment->documentLabel() }} {{ $shipment->invoice_number ?: $shipment->challan_number }} · {{ $shipment->from_name }} → {{ $shipment->to_name }}
                                            · {{ $shipment->lines->map(fn ($l) => rtrim(rtrim((string) $l->qty, '0'), '.').' '.$l->item?->unit.' '.$l->item?->displayName())->implode(', ') }}
                                        </button>
                                    @endforeach
                                </div>
                            @else
                                <div class="text-xs" style="color: var(--text-secondary);">No open outward shipment (Inventory > Outward Shipment). You can still fill the pass by hand below.</div>
                            @endif
                            @error('outwardShipmentId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </div>
                        @if (! $outwardFullForm && ! $outwardShipmentId && trim($poNumber) === '')
                            <div class="md:col-span-2 text-xs" style="color: var(--text-secondary);">Only driver, vehicle and whom they meet are needed now. <button type="button" wire:click="$set('outwardFullForm', true)" class="font-semibold underline" style="color: var(--brand);">Fill package / invoice details by hand</button></div>
                        @else
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Package Number</span>
                            <div class="flex gap-2">
                                <div class="relative flex-1">
                                    <input wire:model.live.debounce.250ms="poNumber" id="poNumber" name="poNumber" autocomplete="off" aria-autocomplete="list" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Enter PO number" />
                                    @if ($poSuggestions)
                                        <div role="listbox" class="absolute z-30 top-full left-0 right-0 mt-1 rounded-xl border shadow-lg overflow-hidden" style="border-color: var(--border); background: var(--surface-1);">
                                            @foreach ($poSuggestions as $suggestion)
                                                <button type="button" wire:click="selectPoSuggestion(@js($suggestion['po_number']), 'outward')" class="w-full text-left px-3 py-2.5 transition-colors hover:bg-black/5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px]" role="option">
                                                    <span class="block text-sm font-semibold" style="color: var(--text-primary);">{{ $suggestion['po_number'] }}</span>
                                                    <span class="block text-xs mt-0.5" style="color: var(--text-muted);">{{ $suggestion['vendor_name'] }} · {{ $suggestion['status'] }} · Select to fetch</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                <button type="button" wire:click="fetchPurchaseOrderDetails" wire:loading.attr="disabled" wire:target="fetchPurchaseOrderDetails" class="shrink-0 rounded-xl px-3 py-2.5 text-xs font-bold text-white disabled:opacity-50" style="background: var(--brand);">Fetch PO</button>
                            </div>
                            @error('poNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Delivery Address</span>
                            <input wire:model="vendorName" id="vendorName" name="vendorName" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Delivery address" />
                            @error('vendorName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Invoice / Doc No</span>
                            <input wire:model="invoiceNumber" id="invoiceNumber" name="invoiceNumber" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Invoice number" />
                            @error('invoiceNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        {{-- Confirmed live: outward had no material/quantity fields at
                             all -- every dispatch saved with material "N/A" and qty 1
                             regardless of what actually left the premises. --}}
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Material</span>
                            <input wire:model="material" id="material" name="material" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="PCM Raw Compound (TN-1 Grade)" />
                            @error('material') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Quantity</span>
                            <input wire:model="invoiceQty" id="invoiceQty" name="invoiceQty" autocomplete="off" type="number" min="1" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="700" @if ($entryType === 'outward' && $fetched && count($productLines) > 1) readonly aria-readonly="true" @endif />
                            @if ($entryType === 'outward' && $fetched && count($productLines) > 1)
                                <span class="text-xs" style="color: var(--text-muted);">Total quantity is calculated from the dispatch quantities entered for each PO item.</span>
                            @endif
                            @error('invoiceQty') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        @if ($entryType === 'outward' && $fetched)
                            <div class="md:col-span-2 rounded-xl border p-3 text-xs" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--text-primary);">
                                <div class="font-semibold">PO details fetched automatically</div>
                                <div class="mt-1">{{ $poNumber }} · {{ $vendorName }} · {{ $material }} · Qty {{ $invoiceQty }} · ₹{{ number_format((float) $invoiceAmount, 2) }}</div>
                                <div class="mt-1" style="color: var(--text-secondary);">PO-controlled fields are filled. Enter the actual package invoice/document number and vehicle details before saving.</div>
                            </div>
                            <div class="md:col-span-2 rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2);">
                                <div class="text-xs font-semibold mb-1" style="color: var(--text-primary);">Items on this dispatch</div>
                                <div class="text-xs mb-3" style="color: var(--text-secondary);">Enter what is physically being shipped for each PO item. The total quantity and tax-inclusive value update from these quantities.</div>
                                <div class="flex flex-col gap-2">
                                    @foreach ($productLines as $i => $line)
                                        <div wire:key="guard-outward-po-line-{{ $loop->index }}" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b pb-2 last:border-b-0 last:pb-0" style="border-color: var(--border);">
                                            <div class="min-w-0">
                                                <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $line['name'] ?? $line['sku'] }}</div>
                                                <div class="text-xs" style="color: var(--text-secondary);">{{ number_format((float) ($line['ordered_qty'] ?? $line['qty']), 0) }} {{ $line['uom'] ?? '' }} ordered{{ isset($line['unit_price']) ? ' · ₹'.number_format((float) $line['unit_price'], 2).' / unit' : '' }}</div>
                                            </div>
                                            <label class="inline-flex items-center gap-2 text-xs" style="color: var(--text-secondary);">
                                                <span>Dispatch qty</span>
                                                <input wire:model.blur="productLines.{{ $i }}.qty" type="number" min="0" max="{{ $line['ordered_qty'] ?? $line['qty'] }}" step="1" class="w-24 rounded-lg border px-2 py-2 text-right text-sm" style="border-color: var(--border); background: var(--surface-1); color: var(--text-primary);" aria-label="Dispatch quantity for {{ $line['name'] ?? $line['sku'] }}" />
                                                <span>{{ $line['uom'] ?? '' }}</span>
                                            </label>
                                            @error("productLines.{$i}.qty") <span role="alert" class="w-full text-right text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                                        </div>
                                    @endforeach
                                </div>
                                @error('productLines') <div role="alert" class="mt-2 text-xs" style="color: var(--status-critical);">{{ $message }}</div> @enderror
                            </div>
                        @endif
                        @endif
                    @endif

                    @if ($entryType !== 'inward')
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">{{ $entryType === 'visitor' ? 'Vehicle / Walk-in' : 'Vehicle Number' }}</span>
                            <input wire:model="vehicleNumber" id="vehicleNumber" name="vehicleNumber" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="{{ $entryType === 'visitor' ? 'WALK-IN' : 'MH 04 GT 5521' }}" />
                            @error('vehicleNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        @unless ($entryType === 'visitor')
                            <label class="space-y-1.5 text-sm">
                                <span class="font-semibold" style="color: var(--text-primary);">Driver Name</span>
                                <input wire:model="driverName" id="driverName" name="driverName" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Driver name" />
                                @error('driverName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            </label>
                        @endunless
                        @if ($entryType === 'outward')
                        <label class="space-y-1.5 text-sm md:col-span-2">
                            <span class="font-semibold" style="color: var(--text-primary);">Meeting whom{{ $entryType === 'inward' ? ' (optional)' : '' }}</span>
                            <select wire:model.live="contactUserId" id="contactUserId" name="contactUserId" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border); background: var(--surface-1); color: var(--text-primary);">
                                <option value="">Whom has the driver come to meet?</option>
                                @foreach ($hostOptions as $host)
                                    <option value="{{ $host->id }}">{{ $host->name }} ({{ $host->role?->label() ?? $host->role?->value }}){{ $host->phone ? ' · '.$host->phone : '' }}</option>
                                @endforeach
                            </select>
                            @if ($contactUserId !== '' && ($contact = $hostOptions->firstWhere('id', (int) $contactUserId)) && $contact->phone)
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact->phone) }}" class="text-xs font-semibold underline" style="color: var(--brand);">Call {{ $contact->name }}: {{ $contact->phone }}</a>
                            @endif
                            @error('contactUserId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        @endif
                    @endif
                </div>

                @if ($entryType === 'outward' && ($outwardFullForm || $outwardShipmentId || trim($poNumber) !== ''))
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-5">
                        @foreach (['invoice' => 'Invoice', 'eway' => 'E-way', 'lr' => 'LR/LRC', 'pod' => 'POD'] as $key => $label)
                            @continue($key === 'pod' && $entryType === 'outward')
                            <label class="flex items-center justify-between gap-2 rounded-xl border px-3 py-2.5 text-sm" style="border-color: var(--border); background: var(--surface-2); color: var(--text-primary);">
                                <span>{{ $label }}</span>
                                <input wire:model.live="documents.{{ $key }}" type="checkbox" class="w-5 h-5 rounded" />
                            </label>
                        @endforeach
                    </div>
                @endif

                @unless ($entryType === 'visitor')
                    <label class="block mt-5 space-y-1.5 text-sm">
                        <span class="font-semibold" style="color: var(--text-primary);">Remarks</span>
                        <textarea wire:model="remarks" rows="2" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Optional note"></textarea>
                    </label>
                @endunless
            </section>

            <aside class="space-y-5">
                <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
                    <h2 class="font-semibold" style="color: var(--text-primary);">Location</h2>
                    <select wire:model.live="location" class="mt-3 w-full rounded-xl border px-3 py-2.5 text-sm" style="border-color: var(--border); color: var(--text-primary); background: var(--surface-2);">
                        @foreach ($locationDetails as $key => $loc)
                            <option value="{{ $key }}">{{ $loc['name'] }} — {{ $loc['warehouseLabel'] }}</option>
                        @endforeach
                    </select>
                    <div class="rounded-xl border p-3 mt-3 text-xs" style="border-color: var(--border); background: var(--surface-2); color: var(--text-secondary);">
                        <strong style="color: var(--text-primary);">{{ $selectedLocation['code'] }}</strong><br>
                        <strong style="color: var(--text-primary);">Inventory warehouse:</strong> {{ $selectedLocation['warehouseLabel'] }}<br>
                        {{ $selectedLocation['contact'] }} - {{ $selectedLocation['phone'] }}<br>
                        {{ $selectedLocation['address'] }}
                    </div>
                    <button type="button" wire:click="useGps" class="mt-3 w-full rounded-xl border px-3 py-3 text-sm font-semibold" style="border-color: var(--border); color: var(--text-primary);">{{ $gps ?: 'Capture GPS' }}</button>
                </section>

                <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
                    <div class="grid grid-cols-2 gap-3 text-center">
                        <div class="rounded-xl border p-3" style="{{ $entryType === 'inward' && ! $fetched ? 'border-color: var(--status-critical);' : 'border-color: var(--border);' }} background: var(--surface-2);">
                            <div class="text-xl font-bold" style="color: {{ $entryType === 'inward' && ! $fetched ? 'var(--status-critical)' : 'var(--text-primary)' }};">{{ $entryType === 'visitor' ? 'Pass' : ($entryType === 'inward' ? ($fetched ? 'Yes' : 'No') : $documentCount.'/4') }}</div>
                            <div class="text-xs" style="color: var(--text-muted);">{{ $entryType === 'visitor' ? 'Type' : ($entryType === 'inward' ? 'Bill Fetched' : 'Docs') }}</div>
                        </div>
                        <div class="rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2);">
                            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $gps ? 'Yes' : 'No' }}</div>
                            <div class="text-xs" style="color: var(--text-muted);">GPS</div>
                        </div>
                    </div>

                    {{-- Informational only, deliberately NOT a block on Save: a guard
                         still needs to be able to log a truck whose PO isn't in the
                         system yet. Unfetched + saved just means GateValidationService
                         won't find a matching PO and will correctly park the entry at
                         Pending Validation for the Store Manager instead of silently
                         marking it validated. --}}
                    @if ($entryType === 'inward' && ! $fetched)
                        <div class="rounded-xl p-3 mt-4 text-xs font-medium" style="background: var(--status-warning-bg); color: var(--status-warning);">
                            Bill not fetched — you can still save, but with an unmatched PO this entry will be held at Pending Validation for the Store Manager to review.
                        </div>
                    @endif

                    <button wire:click="saveEntry" wire:loading.attr="disabled" wire:target="saveEntry" class="mt-4 w-full rounded-xl px-4 py-3 text-sm font-bold text-white disabled:opacity-50" style="background: var(--brand);">
                        <span wire:loading.remove wire:target="saveEntry">
                        {{ $entryType === 'visitor' ? 'Save Visitor Pass' : ($entryType === 'outward' ? 'Save Outward Entry' : 'Save Inward Entry') }}
                        </span><span wire:loading wire:target="saveEntry">Saving entry…</span>
                    </button>
                </section>
            </aside>
        </div>
        @endif
    @endif
</div>
