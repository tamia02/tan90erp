<?php

use App\Models\Inventory\InventoryItem;
use App\Models\Outward\OutwardShipment;
use App\Models\Tan90\MasterData\Customer;
use App\Models\Tan90\MasterData\Location;
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
    }

    public string $purpose = 'sales';
    public string $fromLocationId = '';
    public string $customerId = '';
    public string $toLocationId = '';
    public string $toName = '';
    public string $toGstin = '';
    public string $toState = '';
    public string $toAddress = '';
    public string $salesOrderNumber = '';
    public string $notes = '';
    public array $lines = [['item' => '', 'qty' => '', 'rate' => '']];

    // Documents form for one shipment at a time.
    public ?int $docsFor = null;
    public array $doc = [];
    public $invoiceFile;
    public $irnFile;
    public $ewbFile;
    public string $statusFilter = '';

    public function updatedPurpose(): void
    {
        $this->reset(['customerId', 'toLocationId', 'toName', 'toGstin', 'toState', 'toAddress']);
        $this->resetValidation();
    }

    public function updatedCustomerId(): void
    {
        $customer = $this->customerId ? Customer::find($this->customerId) : null;
        $this->toName = (string) $customer?->name;
        $this->toGstin = (string) $customer?->gstin;
        $this->toState = (string) $customer?->state;
    }

    public function updatedToLocationId(): void
    {
        $location = $this->toLocationId ? Location::find($this->toLocationId) : null;
        $this->toName = (string) $location?->name;
        $this->toGstin = (string) $location?->gstin;
        $this->toState = (string) $location?->state;
        $this->toAddress = (string) $location?->address;
    }

    public function addLine(): void
    {
        $this->lines[] = ['item' => '', 'qty' => '', 'rate' => ''];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines) ?: [['item' => '', 'qty' => '', 'rate' => '']];
    }

    public function createShipment(OutwardShipmentService $service): void
    {
        $this->validate([
            'purpose' => ['required', 'in:'.implode(',', array_keys(OutwardShipment::PURPOSES))],
            // A site's staff ship only from their own site.
            'fromLocationId' => ['required', 'exists:tan90_locations,id', ...(\App\Support\SiteLocation::current() ? ['in:'.\App\Support\SiteLocation::current()] : [])],
            'toName' => ['required', 'string', 'max:255'],
            'toGstin' => ['nullable', 'string', 'size:15'],
            'toState' => ['nullable', 'string', 'max:60'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.rate' => ['nullable', 'numeric', 'min:0'],
        ], [
            'fromLocationId.required' => 'Choose the location the goods leave from.',
            'toName.required' => $this->purpose === 'sales' ? 'Choose or type the customer.' : 'Choose or type where the goods are going.',
            'toGstin.size' => 'A GSTIN has 15 characters.',
        ]);

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
            $lines[] = ['item_id' => $item->id, 'qty' => $line['qty'], 'rate' => $line['rate'] ?: 0];
        }

        $from = Location::findOrFail($this->fromLocationId);
        $shipment = $service->create([
            'purpose' => $this->purpose,
            'from_location_id' => $from->id, 'from_name' => $from->name, 'from_gstin' => $from->gstin, 'from_state' => $from->state,
            'customer_id' => $this->purpose === 'sales' && $this->customerId ? (int) $this->customerId : null,
            'to_location_id' => $this->purpose !== 'sales' && $this->toLocationId ? (int) $this->toLocationId : null,
            'to_name' => trim($this->toName), 'to_gstin' => $this->toGstin ?: null, 'to_state' => $this->toState ?: null,
            'to_address' => $this->toAddress ?: null, 'sales_order_number' => $this->salesOrderNumber ?: null, 'notes' => $this->notes ?: null,
        ], $lines, auth()->user());
        AuditLogger::log('Outward shipment created', "{$shipment->number} · {$shipment->purposeLabel()} · {$shipment->documentLabel()} → {$shipment->to_name}", $shipment);

        session()->flash('success', "{$shipment->number} created ({$shipment->documentLabel()}). ".($shipment->status === 'ready' ? 'Documents complete - waiting for the truck.' : 'Add the documents below. The Guard dispatches it when the truck leaves.'));
        $this->reset(['customerId', 'toLocationId', 'toName', 'toGstin', 'toState', 'toAddress', 'salesOrderNumber', 'notes', 'lines']);
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
        $total = collect($this->lines)->sum(fn ($l) => (float) ($l['qty'] ?: 0) * (float) ($l['rate'] ?: 0));
        $from = $this->fromLocationId ? Location::find($this->fromLocationId) : null;

        // Stock for the items being typed in, so a shortage shows before Create.
        $lineItems = InventoryItem::matchLabels(array_column($this->lines, 'item'));
        $service = app(OutwardShipmentService::class);
        $lineStock = $service->stockPicture($lineItems->pluck('id')->all());

        $shipments = OutwardShipment::with(['lines.item', 'gateEntry'])
            ->when($this->statusFilter === 'open', fn ($q) => $q->whereIn('status', ['documents_pending', 'ready']))
            ->when(! in_array($this->statusFilter, ['open', ''], true), fn ($q) => $q->where('status', $this->statusFilter))
            ->latest('id')->limit(25)->get();
        // Open shipments whose goods are no longer all in stock.
        $openLines = $shipments->whereIn('status', ['documents_pending', 'ready'])->flatMap->lines;
        $inStock = $openLines->isNotEmpty() ? app(InventoryService::class)->balances($openLines->pluck('item')->filter()->unique('id')->values()) : [];
        $short = $shipments->mapWithKeys(fn ($s) => [$s->id => in_array($s->status, ['documents_pending', 'ready'], true)
            ? $s->lines->filter(fn ($l) => $l->item && (float) $l->qty > ($inStock[$l->item_id]['final'] ?? 0) + 0.0005)
                ->map(fn ($l) => $l->item->displayName().': need '.rtrim(rtrim((string) $l->qty, '0'), '.').', in stock '.($inStock[$l->item_id]['final'] ?? 0))->values()->all()
            : []]);

        return [
            'lineItems' => $lineItems,
            'lineStock' => $lineStock,
            'short' => $short,
            'purposes' => OutwardShipment::PURPOSES,
            'locations' => Location::orderBy('name')->get(),
            'fromLocations' => Location::when(\App\Support\SiteLocation::current(), fn ($q, $site) => $q->whereKey($site))->orderBy('name')->get(),
            'customers' => Customer::withAddress()->orderBy('name')->get(['id', 'name', 'gstin', 'state']), // only customers with an address
            'itemOptions' => InventoryItem::whereIn('item_type', $itemTypes)->where('active', true)->get(['sku', 'name', 'code'])
                ->map(fn ($i) => InventoryItem::canSeeSkuName(auth()->user()) ? $i->sku : $i->displayName())->unique()->sort()->values(),
            'lineTotal' => $total,
            'preview' => ($from && trim($this->toName) !== '')
                ? app(OutwardComplianceService::class)->decide($this->purpose, $from->gstin, $this->toGstin ?: null, $from->state, $this->toState ?: null, $total)
                : null,
            'shipments' => $shipments,
        ];
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
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">From (our location)</span>
                <select wire:model.live="fromLocationId" class="{{ $input }}" style="border-color: var(--border);">
                    <option value="">Select…</option>
                    @foreach ($fromLocations as $location)<option value="{{ $location->id }}">{{ $location->name }} - {{ $location->state }} - GSTIN {{ $location->gstin ?: 'not set' }}</option>@endforeach
                </select>
                @error('fromLocationId') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>

            @if ($purpose === 'sales')
                <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Customer</span>
                    <select wire:model.live="customerId" class="{{ $input }}" style="border-color: var(--border);">
                        <option value="">Select from Customer master…</option>
                        @foreach ($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->gstin ? ' - '.$customer->gstin : ' - no GSTIN (B2C)' }}</option>@endforeach
                    </select>
                </label>
            @elseif ($purpose === 'transfer')
                <label class="flex flex-col gap-1 text-sm"><span class="font-medium">To (our location)</span>
                    <select wire:model.live="toLocationId" class="{{ $input }}" style="border-color: var(--border);">
                        <option value="">Select…</option>
                        @foreach ($locations as $location)<option value="{{ $location->id }}">{{ $location->name }} - {{ $location->state }} - GSTIN {{ $location->gstin ?: 'not set' }}</option>@endforeach
                    </select>
                </label>
            @else
                <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Job worker / receiving location</span>
                    <select wire:model.live="toLocationId" class="{{ $input }}" style="border-color: var(--border);">
                        <option value="">Outside job worker (type details below)</option>
                        @foreach ($locations as $location)<option value="{{ $location->id }}">{{ $location->name }} - {{ $location->state }} - GSTIN {{ $location->gstin ?: 'not set' }}</option>@endforeach
                    </select>
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
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">Delivery address (optional)</span><input wire:model="toAddress" class="{{ $input }}" style="border-color: var(--border);"></label>
            <label class="flex flex-col gap-1 text-sm"><span class="font-medium">{{ $purpose === 'sales' ? 'Sales order no. (optional)' : 'Transfer / job work ref. (optional)' }}</span><input wire:model="salesOrderNumber" class="{{ $input }}" style="border-color: var(--border);"></label>
        </div>

        <div class="space-y-2">
            <span class="text-sm font-medium">Items</span>
            <datalist id="outward-items">@foreach ($itemOptions as $sku)<option value="{{ $sku }}"></option>@endforeach</datalist>
            @foreach ($lines as $i => $line)
                <div wire:key="outward-line-{{ $i }}" class="grid grid-cols-12 gap-2 items-start">
                    <div class="col-span-12 sm:col-span-6"><input wire:model.blur="lines.{{ $i }}.item" list="outward-items" placeholder="Item ({{ $purpose === 'sales' ? 'Finished / Trading goods' : 'any item' }})" class="{{ $input }} w-full" style="border-color: var(--border);">
                        @error('lines.'.$i.'.item') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        @error('lines.'.$i.'.item_id') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        @if ($lineItem = $lineItems[mb_strtolower(trim((string) $line['item']))] ?? null)
                            @php $st = $lineStock[$lineItem->id]; $want = (float) ($line['qty'] ?: 0); @endphp
                            <span class="text-xs" style="color: {{ $want > $st['free'] + 0.0005 ? 'var(--status-critical)' : 'var(--text-muted)' }};">
                                In stock {{ rtrim(rtrim(number_format($st['in_stock'], 3, '.', ''), '0'), '.') ?: '0' }} {{ $lineItem->unit }}{{ $st['reserved'] > 0 ? ' · on other open shipments '.rtrim(rtrim(number_format($st['reserved'], 3, '.', ''), '0'), '.') : '' }} · <b>free {{ rtrim(rtrim(number_format($st['free'], 3, '.', ''), '0'), '.') ?: '0' }}</b>{{ $want > $st['free'] + 0.0005 ? ' - not enough stock' : '' }}
                            </span>
                        @endif</div>
                    <input wire:model.live.debounce.500ms="lines.{{ $i }}.qty" type="number" step="0.001" min="0" placeholder="Qty" class="{{ $input }} col-span-4 sm:col-span-2" style="border-color: var(--border);">
                    <input wire:model.live.debounce.500ms="lines.{{ $i }}.rate" type="number" step="0.01" min="0" placeholder="Rate (Rs)" class="{{ $input }} col-span-5 sm:col-span-3" style="border-color: var(--border);">
                    <button type="button" wire:click="removeLine({{ $i }})" class="col-span-3 sm:col-span-1 text-xs font-semibold" style="color: var(--status-critical);">Remove</button>
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
            <div wire:key="outward-shipment-{{ $s->id }}" class="rounded-lg border p-3 mb-3 text-sm" style="border-color: var(--border);">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <span class="font-semibold">{{ $s->number }}</span> · {{ $s->purposeLabel() }} · <b>{{ $s->documentLabel() }}</b>{{ $s->challan_number ? ' '.$s->challan_number : '' }}
                        · {{ $s->from_name }} → {{ $s->to_name }}{{ $s->to_gstin ? ' ('.$s->to_gstin.')' : '' }} · Rs {{ number_format((float) $s->total_value, 2) }}
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                            @foreach ($s->lines as $line){{ rtrim(rtrim((string) $line->qty, '0'), '.') }} {{ $line->item?->unit }} {{ $line->item?->displayName() }}@if (! $loop->last), @endif @endforeach
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
                        @if (! in_array($s->status, ['dispatched', 'cancelled'], true))
                            <button type="button" wire:click="openDocs({{ $s->id }})" class="text-xs font-semibold" style="color: var(--brand);">Documents</button>
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
