<?php

use App\Models\Inventory\InventoryItem;
use App\Models\Outward\OutwardShipment;
use App\Models\Tan90\MasterData\Customer;
use App\Models\Tan90\MasterData\Location;
use App\Models\Tan90\MasterData\PackagingType;
use App\Models\SkuMaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use App\Services\AuditLogger;
use App\Services\Inventory\InventoryService;
use App\Services\Outward\OutwardComplianceService;
use App\Services\Outward\OutwardShipmentService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

// Outward Shipment (was the simple "Dispatch"): purpose -> Tax Invoice or
// Delivery Challan -> e-Invoice IRN -> E-Way Bill, per the client's GST
// flowchart. Documents are created on the government portals and uploaded
// here; stock goes down only on "Mark dispatched".
new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public function mount(): void
    {
        app(InventoryService::class)->syncFromMasters();
        $documentsFor = (int) request()->query('documents', 0);
        if ($documentsFor > 0) {
            $this->openDocs($documentsFor);
        }
    }

    public string $purpose = 'sales';
    public string $fromLocationId = '';
    public string $customerId = '';
    public string $toLocationId = '';
    public string $toName = '';
    public string $toGstin = '';
    public string $toState = '';
    public string $toAddress = '';
    public string $toAddressId = '';
    public string $salesOrderNumber = '';
    public string $notes = '';
    public int $lineKeySequence = 2;
    public array $lines = [['row_key' => 'outward-line-1', 'item' => '', 'qty' => '', 'rate' => '', 'gst_rate' => '0', 'packaging_type_id' => '', 'packaging_quantity' => '', 'box_size' => '']];

    // Documents form for one shipment at a time.
    public ?int $docsFor = null;
    public array $doc = [];
    public $invoiceFile;
    public $irnFile;
    public $ewbFile;
    public string $statusFilter = '';

    public function updatedPurpose(): void
    {
        $this->reset(['fromLocationId', 'customerId', 'toLocationId', 'toName', 'toGstin', 'toState', 'toAddress', 'toAddressId']);
        foreach ($this->lines as $index => $line) {
            $item = InventoryItem::matchLabels([(string) ($line['item'] ?? '')])->first();
            $this->lines[$index]['gst_rate'] = $this->purpose === 'transfer'
                ? '0'
                : ($item ? number_format(app(\App\Services\Outward\OutwardTaxService::class)->defaultGstRate($item), 3, '.', '') : '0');
        }
        $this->resetValidation();
    }

    public function updatedCustomerId(): void
    {
        $customer = $this->customerId ? Customer::find($this->customerId) : null;
        $this->toName = (string) $customer?->name;
        $this->toGstin = (string) $customer?->gstin;
        $this->toState = (string) $customer?->state;
        $this->toAddress = '';
        $this->toAddressId = '';

        // A customer can have multiple GST registrations/addresses. Select a
        // saved address automatically only when the choice is unambiguous.
        $addresses = $customer ? $this->customerAddressQuery()->orderBy('id')->get(['id']) : collect();
        if ($addresses->count() === 1) {
            $this->toAddressId = (string) $addresses->first()->id;
            $this->updatedToAddressId();
        }

    }

    public function updatedToAddressId(): void
    {
        if ($this->toAddressId === '' || $this->toAddressId === 'manual') {
            $this->toAddress = '';
            return;
        }

        $address = $this->customerAddressQuery()->where('id', (int) $this->toAddressId)->first();
        if (! $address) {
            $this->toAddressId = '';
            $this->toAddress = '';
            $this->addError('toAddressId', 'Choose an address saved for this customer.');
            return;
        }

        $this->toAddress = collect([$address->address, $address->street2, $address->city, $address->state, $address->postal_code])
            ->filter(fn ($value) => filled($value))->implode(', ');
        $this->toState = (string) ($address->state ?: $this->toState);
        $customer = Customer::find((int) $this->customerId);
        $this->toGstin = (string) ($address->tax_registration_no ?: $customer?->gstin);
    }

    private function customerAddressQuery()
    {
        return DB::table('tan90_master_addresses')
            ->where('partner_type', 'customer')
            ->where('canonical_type', 'customer')
            ->where('canonical_id', (int) $this->customerId);
    }

    public function updatedToLocationId(): void
    {
        $location = $this->toLocationId ? Location::find($this->toLocationId) : null;
        $this->toName = (string) $location?->name;
        $this->toGstin = (string) $location?->gstin;
        $this->toState = (string) $location?->state;
        $this->toAddress = (string) $location?->address;
    }

    private function selectedFrom(): ?array
    {
        $site = \App\Support\SiteLocation::current();

        if (preg_match('/^vendor:(\d+)$/', $this->fromLocationId, $matches)) {
            if ($this->purpose !== 'sales' || $site) {
                return null;
            }

            $address = DB::table('tan90_master_addresses as addresses')
                ->leftJoin('tan90_vendors as vendors', 'vendors.id', '=', 'addresses.canonical_id')
                ->select('addresses.*', 'vendors.gstin as vendor_gstin', 'vendors.state as vendor_state')
                ->where('addresses.id', (int) $matches[1])
                ->where('addresses.partner_type', 'vendor')
                ->where('addresses.canonical_type', 'vendor')
                ->first();

            if (! $address) {
                return null;
            }

            $addressText = collect([$address->address, $address->street2, $address->city, $address->state ?: $address->vendor_state, $address->postal_code])
                ->filter(fn ($value) => filled($value))
                ->implode(', ');

            return [
                'id' => null,
                'name' => $address->partner_name ?: 'Vendor',
                'address' => mb_substr($addressText, 0, 512),
                'gstin' => $address->tax_registration_no ?: $address->vendor_gstin,
                'state' => $address->state ?: $address->vendor_state,
            ];
        }

        if (! ctype_digit($this->fromLocationId)) {
            return null;
        }

        $location = Location::query()
            ->whereKey((int) $this->fromLocationId)
            ->whereIn('type', ['Manufacturing', 'Warehouse'])
            ->when($site, fn ($query, $siteId) => $query->whereKey($siteId))
            ->first();

        return $location ? [
            'id' => $location->id,
            'name' => $location->name,
            'address' => $location->address,
            'gstin' => $location->gstin,
            'state' => $location->state,
        ] : null;
    }

    public function addLine(): void
    {
        $this->lines[] = ['row_key' => 'outward-line-'.$this->lineKeySequence++, 'item' => '', 'qty' => '', 'rate' => '', 'gst_rate' => '0', 'packaging_type_id' => '', 'packaging_quantity' => '', 'box_size' => ''];
    }

    public function updatedLines($value, ?string $key = null): void
    {
        if ($key === null) {
            return;
        }

        [$index] = explode('.', $key, 2);
        if (str_ends_with($key, '.item') && isset($this->lines[$index])) {
            $item = InventoryItem::matchLabels([(string) $value])->first();
            $price = $item ? SkuMaster::query()->whereRaw('LOWER(sku) = ?', [mb_strtolower($item->sku)])->value('unit_price') : null;
            if (is_numeric($price) && (float) $price > 0) {
                $this->lines[$index]['rate'] = number_format((float) $price, 2, '.', '');
            }
            $this->lines[$index]['gst_rate'] = $this->purpose === 'transfer'
                ? '0'
                : ($item ? number_format(app(\App\Services\Outward\OutwardTaxService::class)->defaultGstRate($item), 3, '.', '') : '0');

            return;
        }

        if (! str_ends_with($key, '.packaging_type_id')) {
            return;
        }
        $type = filled($value) ? PackagingType::find($value) : null;
        if (! $type?->requires_box_size && isset($this->lines[$index])) {
            $this->lines[$index]['box_size'] = '';
        }
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines) ?: [['row_key' => 'outward-line-'.$this->lineKeySequence++, 'item' => '', 'qty' => '', 'rate' => '', 'gst_rate' => '0', 'packaging_type_id' => '', 'packaging_quantity' => '', 'box_size' => '']];
    }

    public function createShipment(OutwardShipmentService $service): void
    {
        $this->validate([
            'purpose' => ['required', 'in:'.implode(',', array_keys(OutwardShipment::PURPOSES))],
            // A site's staff ship only from their own site.
            'fromLocationId' => ['required', 'string', 'max:64'],
            'toLocationId' => $this->purpose === 'sales'
                ? ['nullable', 'integer', 'exists:tan90_locations,id']
                : ['required', 'integer', 'exists:tan90_locations,id'],
            'toName' => ['required', 'string', 'max:255'],
            'toGstin' => ['nullable', 'string', 'size:15'],
            'toState' => ['nullable', 'string', 'max:60'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.packaging_type_id' => ['nullable', 'integer', 'exists:tan90_packaging_types,id'],
            'lines.*.packaging_quantity' => ['nullable', 'integer', 'min:1'],
            'lines.*.box_size' => ['nullable', 'in:Small,Medium,Large'],
        ], [
            'fromLocationId.required' => 'Choose the location the goods leave from.',
            'toName.required' => $this->purpose === 'sales' ? 'Choose or type the customer.' : 'Choose or type where the goods are going.',
            'toGstin.size' => 'A GSTIN has 15 characters.',
        ]);

        if ($this->purpose === 'sales' && $this->toAddressId !== '' && $this->toAddressId !== 'manual') {
            $address = $this->customerAddressQuery()->where('id', (int) $this->toAddressId)->first();
            if (! $address) {
                $this->addError('toAddressId', 'Choose an address saved for this customer.');
                return;
            }
            $this->updatedToAddressId();
        }

        $from = $this->selectedFrom();
        if (! $from) {
            $this->addError('fromLocationId', 'Choose a valid plant, warehouse'.($this->purpose === 'sales' && ! \App\Support\SiteLocation::current() ? ' or vendor location' : '').'.');

            return;
        }

        if ($this->purpose !== 'sales' && ! Location::query()
            ->whereKey((int) $this->toLocationId)
            ->whereIn('type', ['Manufacturing', 'Warehouse'])
            ->exists()) {
            $this->addError('toLocationId', 'Choose a plant or warehouse as the destination.');

            return;
        }

        $items = InventoryItem::matchLabels(array_column($this->lines, 'item'));
        $lines = [];
        foreach ($this->lines as $i => $line) {
            if (trim($line['item']) === '' && trim((string) $line['qty']) === '') {
                continue;
            }
            $item = $items[mb_strtolower(trim($line['item']))] ?? null;
            if (! $item) {
                $this->addError("lines.{$i}.item", 'Pick the item from the list.');

                return;
            }
            $lines[] = [
                'item_id' => $item->id,
                'qty' => $line['qty'],
                'rate' => $line['rate'] ?: 0,
                'gst_rate' => $line['gst_rate'] ?? 0,
                'packaging_type_id' => ($line['packaging_type_id'] ?? null) ?: null,
                'packaging_quantity' => ($line['packaging_quantity'] ?? null) ?: null,
                'box_size' => ($line['box_size'] ?? null) ?: null,
            ];
        }

        $shipment = $service->create([
            'purpose' => $this->purpose,
            'from_location_id' => $from['id'], 'from_name' => $from['name'], 'from_address' => $from['address'], 'from_gstin' => $from['gstin'], 'from_state' => $from['state'],
            'customer_id' => $this->purpose === 'sales' && $this->customerId ? (int) $this->customerId : null,
            'to_location_id' => $this->purpose !== 'sales' && $this->toLocationId ? (int) $this->toLocationId : null,
            'to_name' => trim($this->toName), 'to_gstin' => $this->toGstin ?: null, 'to_state' => $this->toState ?: null,
            'to_address' => $this->toAddress ?: null, 'sales_order_number' => $this->salesOrderNumber ?: null, 'notes' => $this->notes ?: null,
        ], $lines, auth()->user());
        AuditLogger::log('Outward shipment created', "{$shipment->number} · {$shipment->purposeLabel()} · {$shipment->documentLabel()} → {$shipment->to_name}", $shipment);

        session()->flash('success', "{$shipment->number} created ({$shipment->documentLabel()}). ".($shipment->status === 'ready' ? 'Documents complete - waiting for the truck.' : 'Add the documents below. The Guard dispatches it when the truck leaves.'));
        $this->reset(['customerId', 'toLocationId', 'toName', 'toGstin', 'toState', 'toAddress', 'toAddressId', 'salesOrderNumber', 'notes', 'lines']);
        $this->openDocs($shipment->id);
    }

    public function openDocs(int $id): void
    {
        $s = OutwardShipment::findOrFail($id);
        $this->docsFor = $s->id;
        $this->docsSaved = '';
        $this->doc = [
            'invoice_number' => (string) $s->invoice_number, 'invoice_date' => $s->invoice_date?->toDateString() ?? '',
            'irn' => (string) $s->irn, 'irn_ack_number' => (string) $s->irn_ack_number, 'irn_ack_date' => $s->irn_ack_date?->toDateString() ?? '',
            'ewb_number' => (string) $s->ewb_number, 'ewb_date' => $s->ewb_date?->toDateString() ?? '', 'ewb_valid_until' => $s->ewb_valid_until?->toDateString() ?? '',
            'vehicle_number' => (string) $s->vehicle_number, 'transporter' => (string) $s->transporter,
        ];
        $this->reset(['invoiceFile', 'irnFile', 'ewbFile']);
        $this->resetValidation();
    }

    // Result of the last Save documents, shown beside the button.
    public string $docsSaved = '';

    public function saveDocuments(OutwardShipmentService $service): void
    {
        $this->docsSaved = '';
        $shipment = OutwardShipment::findOrFail($this->docsFor);
        $max = config('outward.max_upload_kb');
        $this->validate([
            'doc.invoice_date' => ['nullable', 'date'], 'doc.irn_ack_date' => ['nullable', 'date'],
            'doc.ewb_date' => ['nullable', 'date'], 'doc.ewb_valid_until' => ['nullable', 'date'],
            'doc.irn' => ['nullable', 'string', 'max:80'], 'doc.ewb_number' => ['nullable', 'string', 'max:20'],
            'invoiceFile' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', "max:{$max}"],
            'irnFile' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', "max:{$max}"],
            'ewbFile' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', "max:{$max}"],
        ], [], [
            'doc.invoice_date' => 'invoice date', 'doc.irn_ack_date' => 'Ack date', 'doc.ewb_date' => 'E-Way Bill date',
            'doc.ewb_valid_until' => 'E-Way Bill valid-until date', 'doc.irn' => 'IRN', 'doc.ewb_number' => 'E-Way Bill number',
            'invoiceFile' => 'invoice file', 'irnFile' => 'IRN file', 'ewbFile' => 'E-Way Bill file',
        ]);
        $service->saveDocuments($shipment, $this->doc, ['invoice_file' => $this->invoiceFile, 'irn_file' => $this->irnFile, 'ewb_file' => $this->ewbFile]);
        AuditLogger::log('Outward documents saved', "{$shipment->number} · ".($shipment->status === 'ready' ? 'ready to dispatch' : 'still missing: '.implode(', ', $shipment->missingDocuments())), $shipment);
        $message = $shipment->status === 'ready' ? "{$shipment->number}: all documents in - the Guard can dispatch it when the truck leaves." : "{$shipment->number}: saved. Still missing: ".implode(', ', $shipment->missingDocuments()).'.';
        session()->flash('success', $message);
        $this->openDocs($shipment->id);
        // Shown next to the Save button too: the page-top message is out of sight here.
        $this->docsSaved = $shipment->status === 'ready'
            ? "Saved. All documents are in. Next: when the truck comes, the Guard picks {$shipment->number} in New Gate Pass > Outward."
            : $message;
    }

    public function cancelShipment(int $id, OutwardShipmentService $service): void
    {
        $shipment = OutwardShipment::findOrFail($id);
        $service->cancel($shipment);
        session()->flash('success', "{$shipment->number} cancelled.");
        $this->docsFor = null;
    }

    public function with(): array
    {
        $itemTypes = $this->purpose === 'sales' ? InventoryItem::SELLABLE_TYPES : array_keys(InventoryItem::TYPES);
        $customerAddresses = $this->purpose === 'sales' && $this->customerId
            ? $this->customerAddressQuery()->orderBy('label')->orderBy('city')->get()
            : collect();
        $packagingTypes = PackagingType::query()->active()->where('approval_status', 'active')->orderBy('name')->get(['id', 'name', 'requires_box_size']);
        $site = \App\Support\SiteLocation::current();
        $locations = Location::query()->whereIn('type', ['Manufacturing', 'Warehouse'])
            ->with(['plants:id,tan90_location_id,name', 'warehouses:id,tan90_location_id,code,name,status'])
            ->orderBy('name')->get();
        $fromLocations = $site ? $locations->where('id', $site)->values() : $locations;
        $total = collect($this->lines)->sum(fn ($l) => (float) ($l['qty'] ?: 0) * (float) ($l['rate'] ?: 0));
        $from = $this->fromLocationId ? $this->selectedFrom() : null;

        // Stock for the items being typed in, so a shortage shows before Create.
        $lineItems = InventoryItem::matchLabels(array_column($this->lines, 'item'));
        $service = app(OutwardShipmentService::class);
        $lineStock = $service->stockPicture($lineItems->pluck('id')->all(), null, $from['id'] ?? null); // stock at the chosen internal site, or all sites for a vendor source
        $locationStock = [];
        foreach ($locations as $stockLocation) {
            $locationStock[(int) $stockLocation->id] = $service->stockPicture($lineItems->pluck('id')->all(), null, (int) $stockLocation->id);
        }

        $shipments = OutwardShipment::with(['lines.item', 'lines.packagingType', 'gateEntry'])
            ->when($this->statusFilter === 'open', fn ($q) => $q->whereIn('status', ['documents_pending', 'ready']))
            ->when(! in_array($this->statusFilter, ['open', ''], true), fn ($q) => $q->where('status', $this->statusFilter))
            ->latest('id')->limit(25)->get();
        // Open shipments whose goods are no longer all in stock.
        $openLines = $shipments->whereIn('status', ['documents_pending', 'ready'])->flatMap->lines;
        $inStock = $openLines->isNotEmpty() ? app(InventoryService::class)->balances($openLines->pluck('item')->filter()->unique('id')->values()) : [];
        $short = $shipments->mapWithKeys(fn ($s) => [$s->id => in_array($s->status, ['documents_pending', 'ready'], true)
            ? $s->lines->filter(fn ($l) => $l->item && (float) $l->qty > ($inStock[$l->item_id]['final'] ?? 0) + 0.0005)
                ->map(fn ($l) => $l->item->visibleNameFor(auth()->user()).': need '.rtrim(rtrim((string) $l->qty, '0'), '.').', in stock '.($inStock[$l->item_id]['final'] ?? 0))->values()->all()
            : []]);

        $documentPreview = ($from && trim($this->toName) !== '')
            ? app(OutwardComplianceService::class)->decide($this->purpose, $from['gstin'], $this->toGstin ?: null, $from['state'], $this->toState ?: null, $total)
            : null;
        $taxLines = [];
        foreach ($this->lines as $line) {
            $item = $lineItems[mb_strtolower(trim((string) $line['item']))] ?? null;
            if ($item && (float) ($line['qty'] ?? 0) > 0) {
                $taxLines[] = ['item_id' => $item->id, 'qty' => $line['qty'], 'rate' => $line['rate'] ?: 0, 'gst_rate' => $line['gst_rate'] ?? 0];
            }
        }
        $taxPreview = $from && $taxLines
            ? app(OutwardShipmentService::class)->taxPreview(
                $taxLines,
                $lineItems->keyBy('id'),
                $documentPreview ? $documentPreview['document_type'] !== 'delivery_challan' : true,
                $from['gstin'],
                $this->toGstin ?: null,
                $from['state'],
                $this->toState ?: null,
            )
            : null;
        if ($documentPreview) {
            $documentPreview = app(OutwardComplianceService::class)->decide(
                $this->purpose, $from['gstin'], $this->toGstin ?: null, $from['state'], $this->toState ?: null,
                $taxPreview['total_value'] ?? $total,
            );
        }

        return [
            'lineItems' => $lineItems,
            'lineStock' => $lineStock,
            'locationStock' => $locationStock,
            'short' => $short,
            'purposes' => OutwardShipment::PURPOSES,
            'locations' => $locations,
            'locationOptions' => $this->locationOptions($locations),
            'fromLocations' => $fromLocations,
            'fromOptions' => $this->fromOptions($fromLocations),
            // Include active master records even when their address import is
            // incomplete; users can enter the delivery address manually.
            'customers' => Customer::where('status', 'active')->orderBy('name')->get(['id', 'name', 'gstin', 'state']),
            'customerAddresses' => $customerAddresses,
            'itemOptions' => InventoryItem::whereIn('item_type', $itemTypes)->where('active', true)->get(['sku', 'name', 'code'])
                ->map(fn ($i) => $i->visibleNameFor(auth()->user()))->unique()->sort()->values(),
            'packagingTypes' => $packagingTypes,
            'lineTotal' => $total,
            'preview' => $documentPreview,
            'taxPreview' => $taxPreview,
            'taxItems' => $lineItems->keyBy('id'),
            'from' => $from,
            'shipments' => $shipments,
        ];
    }

    private function locationOptions(Collection $locations): array
    {
        return $locations->map(fn (Location $location) => [
            'value' => (string) $location->id,
            'label' => $this->locationOptionLabel($location),
        ])->all();
    }

    private function locationOptionLabel(Location $location): string
    {
        $parts = [];
        if ($location->plants->isNotEmpty()) {
            $parts[] = 'Plant: '.$location->plants->pluck('name')->implode(', ');
        }
        if ($location->warehouses->isNotEmpty()) {
            $activeWarehouses = $location->warehouses->where('status', 'active');
            if ($activeWarehouses->isNotEmpty()) {
                $parts[] = 'Warehouse: '.$activeWarehouses->map(fn ($warehouse) => $warehouse->code.' — '.$warehouse->name)->implode(', ');
            }
        }
        $entities = $parts ? ' — '.implode(' · ', $parts) : ' ('.$location->type.')';

        return $location->name.$entities.($location->state ? ' - '.$location->state : '').' - GSTIN '.($location->gstin ?: 'not set');
    }

    private function fromOptions(Collection $locations): array
    {
        $site = \App\Support\SiteLocation::current();

        $options = $locations->map(fn (Location $location) => [
            'value' => (string) $location->id,
            'label' => $this->locationOptionLabel($location),
        ])->all();

        if ($this->purpose === 'sales' && ! $site) {
            $vendors = DB::table('tan90_master_addresses as addresses')
                ->leftJoin('tan90_vendors as vendors', 'vendors.id', '=', 'addresses.canonical_id')
                ->select('addresses.*', 'vendors.gstin as vendor_gstin', 'vendors.state as vendor_state')
                ->where('addresses.partner_type', 'vendor')
                ->where('addresses.canonical_type', 'vendor')
                ->orderBy('addresses.partner_name')->orderBy('addresses.city')->get();

            foreach ($vendors as $address) {
                $where = collect([$address->label, $address->address, $address->street2, $address->city, $address->state ?: $address->vendor_state])
                    ->filter(fn ($value) => filled($value))->implode(', ');
                $gstin = $address->tax_registration_no ?: ($address->vendor_gstin ?: 'not set');
                $options[] = [
                    'value' => 'vendor:'.$address->id,
                    'label' => ($address->partner_name ?: 'Vendor').($where ? ' - '.$where : '').' - GSTIN '.$gstin.' (Vendor)',
                ];
            }
        }

        return $options;
    }
}; ?>

@php
    $badge = fn ($status) => match ($status) {
        'ready' => ['Documents complete', 'var(--status-good)', 'var(--status-good-bg)'],
        'dispatched' => ['Dispatched', 'var(--text-secondary)', 'var(--surface-2)'],
        'cancelled' => ['Cancelled', 'var(--text-muted)', 'var(--surface-2)'],
        default => ['Documents pending', 'var(--status-warning)', 'var(--status-warning-bg)'],
    };
    $input = 'rounded-lg border px-3 py-2 text-sm';
@endphp
<div>
    @include('partials.inventory-tabs', ['current' => 'inventory.dispatch', 'title' => 'Outward Shipment', 'subtitle' => 'Send goods out the GST way: the system decides Tax Invoice or Delivery Challan, e-Invoice IRN and E-Way Bill. Create them on the portals and upload them here. When the truck comes: Guard makes the outward gate pass, Store Manager assigns the dock, goods are loaded, and the Guard dispatches it at exit - stock goes down only then.'])
    @error('dispatch') <div role="alert" class="rounded-xl border px-4 py-3 mb-4 text-sm" style="background: var(--status-critical-bg); border-color: var(--status-critical); color: var(--status-critical);">{{ $message }}</div> @enderror
    @error('cancel') <div role="alert" class="rounded-xl border px-4 py-3 mb-4 text-sm" style="background: var(--status-critical-bg); border-color: var(--status-critical); color: var(--status-critical);">{{ $message }}</div> @enderror

    {{-- 1. New outward shipment --}}
    <form wire:submit="createShipment" class="rounded-2xl border p-5 space-y-4" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="text-lg font-semibold" style="color: var(--text-primary);">New outward shipment</h2>
        <div>
            <span class="text-sm font-medium">What is the purpose of the movement?</span>
            <div class="flex flex-wrap gap-2 mt-1">
                @foreach ($purposes as $value => $label)
                    <label class="flex items-center gap-2 text-sm border rounded-lg px-3 py-2 cursor-pointer" style="border-color: {{ $purpose === $value ? 'var(--brand)' : 'var(--border)' }};">
                        <input type="radio" wire:model.live="purpose" value="{{ $value }}"> {{ $label }}
                    </label>
                @endforeach
            </div>
        </div>

        <div class="grid sm:grid-cols-2 gap-3">
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">From (Tan90 location the shipment leaves from)</span>
                <select wire:model.live="fromLocationId" class="{{ $input }}" style="border-color: var(--border);">
                    <option value="">Select…</option>
                    @foreach ($fromOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                </select>
                <span class="text-xs" style="color: var(--text-muted);">Choose the site whose stock will be checked and reduced when the truck exits. Only locations in your access scope are listed.</span>
                @error('fromLocationId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>

            @if ($purpose === 'sales')
                <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Customer</span>
                    <select wire:model.live="customerId" class="{{ $input }}" style="border-color: var(--border);">
                        <option value="">Select from Customer master…</option>
                        @foreach ($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->gstin ? ' - '.$customer->gstin : ' - no GSTIN (B2C)' }}</option>@endforeach
                    </select>
                </label>
            @else
                <label class="flex flex-col gap-1 text-sm"><span class="font-medium">To (our location)</span>
                    <select wire:model.live="toLocationId" class="{{ $input }}" style="border-color: var(--border);">
                        <option value="">Select…</option>
                        @foreach ($locationOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach
                    </select>
                    @error('toLocationId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </label>
            @endif
        </div>

        <div class="grid sm:grid-cols-4 gap-3">
            <label class="flex flex-col gap-1 text-sm sm:col-span-2"><span class="font-medium">{{ $purpose === 'sales' ? 'Customer name' : 'Receiver name' }}</span>
                <input wire:model.live.debounce.500ms="toName" class="{{ $input }}" style="border-color: var(--border);">
                @error('toName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Receiver GSTIN</span>
                <input wire:model.live.debounce.500ms="toGstin" maxlength="15" class="{{ $input }} uppercase" style="border-color: var(--border);" placeholder="Blank = unregistered">
                @error('toGstin') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Receiver state</span>
                <input wire:model.live.debounce.500ms="toState" class="{{ $input }}" style="border-color: var(--border);">
            </label>
        </div>
        <div class="grid sm:grid-cols-2 gap-3">
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Delivery address (optional)</span>
                @if ($purpose === 'sales')
                    <select wire:model.live="toAddressId" class="{{ $input }}" style="border-color: var(--border);" @disabled(!$customerId)>
                        <option value="">{{ $customerId ? 'Select a saved customer address…' : 'Select a customer first…' }}</option>
                        @foreach ($customerAddresses as $address)<option value="{{ $address->id }}">{{ $address->label ?: trim(implode(', ', array_filter([$address->city, $address->state, $address->postal_code]))) }}</option>@endforeach
                        @if ($customerId)<option value="manual">Enter address manually</option>@endif
                    </select>
                    @error('toAddressId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    @if ($toAddressId === 'manual' || ($customerId && $customerAddresses->isEmpty()))
                        <input wire:model="toAddress" class="{{ $input }}" style="border-color: var(--border);" placeholder="Delivery address">
                    @elseif ($toAddress)
                        <textarea readonly rows="2" class="{{ $input }}" style="border-color: var(--border);">{{ $toAddress }}</textarea>
                    @endif
                @else
                    <input wire:model="toAddress" class="{{ $input }}" style="border-color: var(--border);">
                @endif
            </label>
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">{{ $purpose === 'sales' ? 'Sales order no. (optional)' : ($purpose === 'job_work' ? 'Delivery Challan ref. (optional)' : 'Transfer order ref. (optional)') }}</span><input wire:model="salesOrderNumber" class="{{ $input }}" style="border-color: var(--border);"></label>
        </div>

        <div class="space-y-2">
            <span class="text-sm font-medium">Items</span>
            @foreach ($lines as $i => $line)
                <div wire:key="{{ $line['row_key'] ?? 'outward-line-'.$i }}" class="grid grid-cols-12 gap-2 items-start rounded-lg border p-3" style="border-color: var(--border);">
                    <div class="col-span-12 sm:col-span-6"><select wire:model.change="lines.{{ $i }}.item" aria-label="Item ({{ $purpose === 'sales' ? 'Finished / Trading goods' : 'any item' }})" class="{{ $input }} w-full" style="border-color: var(--border);">
                        <option value="">Select {{ $purpose === 'sales' ? 'finished or trading item' : 'item' }}…</option>
                        @foreach ($itemOptions as $sku)<option value="{{ $sku }}">{{ $sku }}</option>@endforeach
                    </select>
                        @error('lines.'.$i.'.item') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        @error('lines.'.$i.'.item_id') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        @if ($lineItem = $lineItems[mb_strtolower(trim((string) $line['item']))] ?? null)
                            @php $st = $lineStock[$lineItem->id]; $want = (float) ($line['qty'] ?: 0); @endphp
                            <span class="text-xs" style="color: {{ $want > $st['free'] + 0.0005 ? 'var(--status-critical)' : 'var(--text-muted)' }};">
                                In stock {{ rtrim(rtrim(number_format($st['in_stock'], 3, '.', ''), '0'), '.') ?: '0' }} {{ $lineItem->unit }}{{ $st['reserved'] > 0 ? ' · on other open shipments '.rtrim(rtrim(number_format($st['reserved'], 3, '.', ''), '0'), '.') : '' }} · <b>free {{ rtrim(rtrim(number_format($st['free'], 3, '.', ''), '0'), '.') ?: '0' }}</b>{{ $want > $st['free'] + 0.0005 ? ' - not enough stock' : '' }}
                            </span>
                            @if ($from && $locations->count() > 1)
                                @php
                                    $otherStockLocations = $locations
                                        ->filter(fn ($location) => (int) $location->id !== (int) $from['id'])
                                        ->filter(fn ($location) => ($locationStock[(int) $location->id][$lineItem->id]['in_stock'] ?? 0) > 0);
                                @endphp
                                @if ($otherStockLocations->isNotEmpty())
                                    <span class="block text-xs" style="color:var(--text-muted);">Stock at other visible locations: @foreach ($otherStockLocations as $otherLocation){{ $otherLocation->name }} {{ rtrim(rtrim(number_format($locationStock[(int) $otherLocation->id][$lineItem->id]['in_stock'], 3, '.', ''), '0'), '.') }}{{ $loop->last ? '' : ' · ' }}@endforeach</span>
                                @endif
                            @endif
                        @endif</div>
                    <input wire:model="lines.{{ $i }}.qty" type="number" step="0.001" min="0" placeholder="Qty" aria-label="Item quantity" class="{{ $input }} col-span-4 sm:col-span-2" style="border-color: var(--border);">
                    <input wire:model="lines.{{ $i }}.rate" type="number" step="0.01" min="0" placeholder="Rate (Rs)" aria-label="Rate in rupees" class="{{ $input }} col-span-5 sm:col-span-3" style="border-color: var(--border);">
                    <button type="button" wire:click="removeLine({{ $i }})" class="col-span-3 sm:col-span-1 text-xs font-semibold" style="color: var(--status-critical);">Remove</button>
                    <select wire:model.live="lines.{{ $i }}.packaging_type_id" aria-label="Packaging type" class="{{ $input }} col-span-6 sm:col-span-4" style="border-color: var(--border);">
                        <option value="">Packaging type (optional)</option>
                        @foreach ($packagingTypes as $packagingType)
                            <option value="{{ $packagingType->id }}">{{ $packagingType->name }}</option>
                        @endforeach
                    </select>
                    <input wire:model="lines.{{ $i }}.packaging_quantity" type="number" min="1" step="1" inputmode="numeric" aria-label="Package quantity" placeholder="Package quantity" class="{{ $input }} col-span-6 sm:col-span-4" style="border-color: var(--border);">
                    <label class="col-span-12 sm:col-span-4 flex flex-col gap-1 text-xs" style="color: var(--text-secondary);">
                            <span>Editable GST rate (%)</span>
                            <input wire:model="lines.{{ $i }}.gst_rate" type="number" step="0.01" min="0" max="100" inputmode="decimal" aria-label="Editable GST rate" placeholder="0" class="{{ $input }}" style="border-color: var(--border);">
                            @error('lines.'.$i.'.gst_rate') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            <span>{{ ($preview['document_type'] ?? null) === 'delivery_challan' ? 'Printed for reference; no tax is charged on a delivery challan.' : 'Rate prefilled from the SKU master; the selected rate is saved on this document.' }}</span>
                        </label>
                    @if (($packagingTypes->firstWhere('id', (int) ($line['packaging_type_id'] ?? 0))?->requires_box_size ?? false))
                        <select wire:model="lines.{{ $i }}.box_size" aria-label="Box size" class="{{ $input }} col-span-12 sm:col-span-4" style="border-color: var(--border);">
                            <option value="">Select box size</option>
                            <option value="Small">Small</option>
                            <option value="Medium">Medium</option>
                            <option value="Large">Large</option>
                        </select>
                    @endif
                    @error('lines.'.$i.'.packaging_type_id') <span class="col-span-12 text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    @error('lines.'.$i.'.packaging_quantity') <span class="col-span-12 text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    @error('lines.'.$i.'.box_size') <span class="col-span-12 text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </div>
            @endforeach
            @error('lines') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            <div class="flex items-center justify-between">
                <button type="button" wire:click="addLine" class="text-xs font-semibold" style="color: var(--brand);">+ Add item</button>
                <span class="text-sm font-semibold">Consignment value: Rs {{ number_format($lineTotal, 2) }}</span>
            </div>
        </div>

        @if ($preview)
            <div class="rounded-lg border p-3 text-sm" style="border-color: var(--brand); background: var(--brand-bg);">
                <div class="font-semibold mb-1" style="color: var(--brand);">Documents needed: {{ \App\Models\Outward\OutwardShipment::DOCUMENT_TYPES[$preview['document_type']] }}{{ $preview['requires_irn'] ? ' + e-Invoice IRN' : '' }}{{ $preview['requires_ewb'] ? ' + E-Way Bill' : '' }}{{ ! $preview['requires_ewb'] && $preview['document_type'] === 'delivery_challan' ? ' (print challan only)' : '' }}</div>
                <ol class="list-decimal pl-5 space-y-0.5" style="color: var(--text-secondary);">@foreach ($preview['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
            </div>
        @endif
        @if ($taxPreview)
            <div class="rounded-lg border p-3 text-sm" style="border-color: var(--border); background: var(--surface-1);">
                <div class="font-semibold mb-2">{{ ($preview['document_type'] ?? null) === 'delivery_challan' ? 'GST rate shown by item · no GST charged on this challan' : 'GST by item · '.($taxPreview['needs_state'] ? 'receiver state required' : ($taxPreview['igst'] > 0 ? 'IGST' : ($taxPreview['cgst'] > 0 ? 'CGST + SGST' : 'no GST rate set'))) }}</div>
                <div class="overflow-x-auto"><table class="w-full text-xs min-w-[560px]">
                    <thead><tr class="text-left" style="color: var(--text-muted);"><th class="py-1">Item</th><th class="py-1 text-right">GST %</th><th class="py-1 text-right">Taxable value</th><th class="py-1 text-right">CGST</th><th class="py-1 text-right">SGST</th><th class="py-1 text-right">IGST</th></tr></thead>
                    <tbody>@foreach ($taxPreview['lines'] as $taxLine)
                        <tr style="border-top: 1px solid var(--border);"><td class="py-1">{{ $taxItems[$taxLine['item_id']]?->visibleNameFor(auth()->user()) ?? 'Item' }}</td><td class="py-1 text-right">{{ number_format($taxLine['gst_rate'], 2) }}%</td><td class="py-1 text-right">Rs {{ number_format($taxLine['value'], 2) }}</td><td class="py-1 text-right">{{ $taxLine['cgst_amount'] > 0 ? 'Rs '.number_format($taxLine['cgst_amount'], 2) : '—' }}</td><td class="py-1 text-right">{{ $taxLine['sgst_amount'] > 0 ? 'Rs '.number_format($taxLine['sgst_amount'], 2) : '—' }}</td><td class="py-1 text-right">{{ $taxLine['igst_amount'] > 0 ? 'Rs '.number_format($taxLine['igst_amount'], 2) : '—' }}</td></tr>
                    @endforeach</tbody>
                    <tfoot><tr style="border-top: 1px solid var(--border);"><td class="py-2 font-semibold" colspan="2">Total</td><td class="py-2 text-right">Rs {{ number_format($taxPreview['subtotal_value'], 2) }}</td><td class="py-2 text-right">Rs {{ number_format($taxPreview['cgst'], 2) }}</td><td class="py-2 text-right">Rs {{ number_format($taxPreview['sgst'], 2) }}</td><td class="py-2 text-right">Rs {{ number_format($taxPreview['igst'], 2) }}</td></tr></tfoot>
                </table></div>
                @if (($preview['document_type'] ?? null) === 'delivery_challan')<p class="mt-2 text-xs" style="color: var(--text-secondary);">The selected GST rate is printed for reference. Delivery Challans remain non-taxable; tax and total stay unchanged.</p>@elseif ($taxPreview['needs_state'])<p class="mt-2 text-xs" style="color: var(--status-critical);">Enter the receiver state. GST rate comes from each item’s GST % custom field; the app will split it by state.</p>@else<p class="mt-2 text-xs" style="color: var(--text-secondary);">GST {{ number_format($taxPreview['tax_total'], 2) }} · Total including GST Rs {{ number_format($taxPreview['total_value'], 2) }}</p>@endif
            </div>
        @endif
        <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Notes (optional)</span><input wire:model="notes" class="{{ $input }}" style="border-color: var(--border);"></label>
        <button type="submit" wire:loading.attr="disabled" wire:target="createShipment" class="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Create outward shipment</button>
    </form>

    {{-- 2. Shipments: documents, then dispatch --}}
    <section class="rounded-2xl border p-5 mt-6" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Outward shipments</h2>
            <select wire:model.live="statusFilter" class="{{ $input }}" style="border-color: var(--border);">
                <option value="">All (latest first)</option>
                <option value="open">Open (not dispatched yet)</option>
                <option value="documents_pending">Documents pending</option>
                <option value="ready">Documents complete</option>
                <option value="dispatched">Dispatched</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>
        @forelse ($shipments as $s)
            @php [$label, $fg, $bg] = $badge($s->status); @endphp
            <div id="outward-shipment-{{ $s->id }}" wire:key="outward-shipment-{{ $s->id }}" class="rounded-lg border p-3 mb-3 text-sm" style="border-color: var(--border);">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <a href="{{ route('inventory.outward.show', $s) }}" wire:navigate class="font-semibold hover:underline" style="color:var(--brand);">{{ $s->number }}</a> · {{ $s->purposeLabel() }} · <b>{{ $s->documentLabel() }}</b>{{ $s->challan_number ? ' '.$s->challan_number : '' }}
                        · {{ $s->from_name }}{{ $s->from_address ? ' · '.$s->from_address : '' }} → {{ $s->to_name }}{{ $s->to_gstin ? ' ('.$s->to_gstin.')' : '' }} · Rs {{ number_format((float) $s->total_value, 2) }}
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                            @foreach ($s->lines as $line){{ rtrim(rtrim((string) $line->qty, '0'), '.') }} {{ $line->item?->unit }} {{ $line->item?->visibleNameFor(auth()->user()) }}@if (! $loop->last), @endif @endforeach
                            · Needs: {{ $s->documentLabel() }}{{ $s->requires_irn ? ', IRN' : '' }}{{ $s->requires_ewb ? ', E-Way Bill' : '' }}
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold" style="color: {{ $fg }}; background: {{ $bg }};">{{ $label }}</span>
                        @if ($short[$s->id] ?? [])
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold" style="color: var(--status-critical); background: var(--status-critical-bg);" title="{{ implode('; ', $short[$s->id]) }}">Short: {{ implode('; ', $short[$s->id]) }}</span>
                        @endif
                        @if ($s->challan_number)<a href="{{ route('inventory.outward.challan', $s) }}" target="_blank" class="text-xs font-semibold" style="color: var(--brand);">Print challan</a>@endif
                        @if ($s->purpose === 'transfer')<a href="{{ route('inventory.outward.transfer-order', $s) }}" target="_blank" class="text-xs font-semibold" style="color: var(--brand);">Print transfer order</a>@endif
                        @if ($s->document_type === 'tax_invoice')<a href="{{ route('inventory.outward.tax-invoice', $s) }}" target="_blank" class="text-xs font-semibold" style="color: var(--brand);">System invoice preview</a>@endif
                        @if ($s->invoice_file)<a href="{{ route('inventory.outward.file', [$s, 'invoice']) }}" target="_blank" class="text-xs font-semibold" style="color: var(--brand);">Uploaded invoice</a>@endif
                        @if (! in_array($s->status, ['dispatched', 'cancelled'], true))
                            <button type="button" wire:click="openDocs({{ $s->id }})" class="text-xs font-semibold" style="color: var(--brand);">Upload / manage documents</button>
                        @endif
                        @if ($s->status === 'dispatched')
                            <span class="text-xs" style="color: var(--text-muted);">Truck left {{ $s->dispatched_at?->format('d M, H:i') }}{{ $s->gateEntry ? ' · '.$s->gateEntry->gate_no : '' }}</span>
                        @elseif ($s->gateEntry)
                            <span class="text-xs font-semibold" style="color: var(--brand);" title="Stock goes down when the Guard confirms the truck's exit">Truck {{ $s->gateEntry->vehicle_number }} · {{ $s->gateEntry->gate_no }} · {{ \App\Support\GateStatusLabels::label($s->gateEntry->status) }}</span>
                        @elseif ($s->status !== 'cancelled')
                            <span class="text-xs" style="color: var(--text-muted);">Waiting for the truck - Guard makes the outward gate pass when it arrives</span>
                        @endif
                    </div>
                </div>

                @if ($docsFor === $s->id)
                    <div class="mt-3 border-t pt-3 space-y-3" style="border-color: var(--border);">
                        <ol class="list-decimal pl-5 text-xs space-y-0.5" style="color: var(--text-secondary);">@foreach ($s->decision_steps ?? [] as $step)<li>{{ $step }}</li>@endforeach</ol>
                        @if ($s->needsInvoice())
                            <div class="grid sm:grid-cols-3 gap-2">
                                <label class="flex flex-col gap-1 text-xs"><span class="font-semibold">{{ $s->documentLabel() }} no. *</span><input wire:model="doc.invoice_number" class="{{ $input }}" style="border-color: var(--border);"></label>
                                <label class="flex flex-col gap-1 text-xs"><span class="font-semibold">Date *</span><input wire:model="doc.invoice_date" type="date" class="{{ $input }}" style="border-color: var(--border);"></label>
                                <label class="flex flex-col gap-1 text-xs"><span class="font-semibold">{{ $s->documentLabel() }} file (PDF/JPG) *</span><input wire:model="invoiceFile" type="file" accept=".pdf,image/*" class="text-xs">
                                    @if ($s->invoice_file)<a href="{{ route('inventory.outward.file', [$s, 'invoice']) }}" target="_blank" class="underline" style="color: var(--brand);">uploaded - view</a>@endif
                                    @error('invoiceFile') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror</label>
                            </div>
                        @endif
                        @if ($s->requires_irn)
                            <div class="grid sm:grid-cols-4 gap-2">
                                <label class="flex flex-col gap-1 text-xs sm:col-span-2"><span class="font-semibold">e-Invoice IRN *</span><input wire:model="doc.irn" class="{{ $input }}" style="border-color: var(--border);"></label>
                                <label class="flex flex-col gap-1 text-xs"><span class="font-semibold">Ack No. *</span><input wire:model="doc.irn_ack_number" class="{{ $input }}" style="border-color: var(--border);"></label>
                                <label class="flex flex-col gap-1 text-xs"><span class="font-semibold">Ack date</span><input wire:model="doc.irn_ack_date" type="date" class="{{ $input }}" style="border-color: var(--border);"></label>
                                <label class="flex flex-col gap-1 text-xs sm:col-span-4"><span class="font-semibold">Signed e-Invoice / QR file (optional)</span><input wire:model="irnFile" type="file" accept=".pdf,image/*" class="text-xs">
                                    @if ($s->irn_file)<a href="{{ route('inventory.outward.file', [$s, 'irn']) }}" target="_blank" class="underline" style="color: var(--brand);">uploaded - view</a>@endif</label>
                            </div>
                        @endif
                        @if ($s->requires_ewb)
                            <div class="grid sm:grid-cols-4 gap-2">
                                <label class="flex flex-col gap-1 text-xs"><span class="font-semibold">E-Way Bill no. *</span><input wire:model="doc.ewb_number" class="{{ $input }}" style="border-color: var(--border);"></label>
                                <label class="flex flex-col gap-1 text-xs"><span class="font-semibold">E-Way Bill date</span><input wire:model="doc.ewb_date" type="date" class="{{ $input }}" style="border-color: var(--border);"></label>
                                <label class="flex flex-col gap-1 text-xs"><span class="font-semibold">Valid until *</span><input wire:model="doc.ewb_valid_until" type="date" class="{{ $input }}" style="border-color: var(--border);"></label>
                                <label class="flex flex-col gap-1 text-xs"><span class="font-semibold">E-Way Bill file *</span><input wire:model="ewbFile" type="file" accept=".pdf,image/*" class="text-xs">
                                    @if ($s->ewb_file)<a href="{{ route('inventory.outward.file', [$s, 'ewb']) }}" target="_blank" class="underline" style="color: var(--brand);">uploaded - view</a>@endif
                                    @error('ewbFile') <span style="color: var(--status-critical);">{{ $message }}</span> @enderror</label>
                            </div>
                        @endif
                        @if ($s->document_type === 'delivery_challan' && ! $s->requires_ewb)
                            <p class="text-xs" style="color: var(--text-secondary);">No E-Way Bill needed - print the Delivery Challan ({{ $s->challan_number }}) and send it with the goods.</p>
                        @endif
                        <div class="grid sm:grid-cols-2 gap-2">
                            <label class="flex flex-col gap-1 text-xs"><span class="font-semibold">Vehicle no.</span><input wire:model="doc.vehicle_number" class="{{ $input }}" style="border-color: var(--border);"></label>
                            <label class="flex flex-col gap-1 text-xs"><span class="font-semibold">Transporter</span><input wire:model="doc.transporter" class="{{ $input }}" style="border-color: var(--border);"></label>
                        </div>
                        @if ($missing = $s->missingDocuments())
                            <p class="text-xs font-semibold" style="color: var(--status-warning);">Still missing: {{ implode(', ', $missing) }}</p>
                        @endif
                        @if ($errors->any())
                            <div role="alert" class="rounded-lg border px-3 py-2 text-xs" style="border-color: var(--status-critical); background: var(--status-critical-bg); color: var(--status-critical);">
                                <div class="font-semibold">Not saved - please fix:</div>
                                <ul class="list-disc pl-4">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
                            </div>
                        @elseif ($docsSaved)
                            <div role="status" class="rounded-lg border px-3 py-2 text-xs font-semibold" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--status-good);">{{ $docsSaved }}</div>
                        @endif
                        <div class="flex gap-2">
                            <button type="button" wire:click="saveDocuments" wire:loading.attr="disabled" wire:target="saveDocuments,invoiceFile,irnFile,ewbFile" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50" style="background: var(--brand);">Save documents</button>
                            <button type="button" wire:click="cancelShipment({{ $s->id }})" wire:confirm="Cancel {{ $s->number }}?" class="rounded-lg border px-3 py-1.5 text-xs font-semibold" style="border-color: var(--border); color: var(--status-critical);">Cancel shipment</button>
                            <button type="button" wire:click="$set('docsFor', null)" class="text-xs" style="color: var(--text-muted);">Close</button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm" style="color: var(--text-muted);">No outward shipments here yet.</p>
        @endforelse
    </section>
</div>
