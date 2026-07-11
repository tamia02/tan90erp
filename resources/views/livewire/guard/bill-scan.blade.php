<?php

use App\Models\GateEntry;
use App\Models\ValidationIssue;
use App\Models\VendorSubmission;
use App\Services\AuditLogger;
use App\Services\GateValidationService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $entryType = 'inward';
    public bool $billScanned = false;
    public bool $ocrReady = false;
    public string $gps = '';
    public ?array $saved = null;
    public bool $fetched = false;

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
    public string $remarks = '';

    public array $documents = ['invoice' => false, 'eway' => false, 'lr' => false, 'pod' => false];
    public array $productLines = [];

    private const LOCATIONS = [
        'delhi' => ['name' => 'DELHI (MU)', 'code' => 'DELHI-TAN90-MU', 'company' => 'TAN90', 'contact' => 'Ram Prakash', 'phone' => '+91 92056 54865', 'address' => '96QX+3XH Pali, Haryana', 'gps' => '28.3078 N, 76.8450 E'],
        'chennai' => ['name' => 'CHENNAI (MU)', 'code' => 'CHENNAI-TAN90-MU', 'company' => 'TAN90', 'contact' => 'Karthick', 'phone' => '+91 89258 00797', 'address' => '75/1A1, Poonamallee Bypass Rd, Senneer Kuppam, Chennai, Tamil Nadu 600077', 'gps' => '13.0646 N, 80.1016 E'],
        'bhiwandi' => ['name' => 'Bhiwandi WH (MU)', 'code' => 'Mumbai-TAN90-MU', 'company' => 'TAN90', 'contact' => 'Shamim', 'phone' => '+91 89258 46275', 'address' => 'S.No. 90 H No 2 situated at Vadpa, Bhiwandi, Maharashtra 421302', 'gps' => '19.2967 N, 73.0631 E'],
    ];

    public function with(): array
    {
        return [
            'isVisitor' => $this->entryType === 'visitor',
            'modeLabel' => $this->modeLabel(),
            'selectedLocation' => self::LOCATIONS[$this->location] ?? self::LOCATIONS['bhiwandi'],
            'locationDetails' => self::LOCATIONS,
            'documentCount' => collect($this->documents)->filter()->count(),
            'documentSummary' => $this->documentSummary(),
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

    public function useGps(): void
    {
        $selected = self::LOCATIONS[$this->location] ?? self::LOCATIONS['bhiwandi'];
        $this->gps = $selected['gps'].' ('.$selected['code'].')';
    }

    /** Inward only — the guard keys in the bill number and everything else
     * (PO number, vendor, quantity, material) is pulled from the vendor's
     * own portal submission for that bill instead of being typed twice. */
    public function fetchBillDetails(): void
    {
        $this->resetErrorBag('invoiceNumber');
        $this->validateOnly('invoiceNumber', ['invoiceNumber' => ['required', 'string']]);

        $submission = VendorSubmission::where('invoice_number', $this->invoiceNumber)->latest()->first();

        if (! $submission) {
            $this->fetched = false;
            $this->addError('invoiceNumber', 'No vendor submission found for this bill number.');

            return;
        }

        $this->poNumber = $submission->po_number;
        $this->vendorName = $submission->vendor_name;
        $this->invoiceQty = (string) $submission->invoice_qty;
        $this->material = $submission->material;
        $this->fetched = true;
    }

    public function fillSample(): void
    {
        $this->ocrReady = true;
        $this->location = 'bhiwandi';

        if ($this->entryType === 'visitor') {
            $this->billScanned = false;
            $this->poNumber = 'Store Manager';
            $this->vendorName = 'Tesmed Service Partner';
            $this->invoiceNumber = 'VIS-'.now()->format('ymdHis');
            $this->invoiceQty = '1';
            $this->material = 'Preventive maintenance visit';
            $this->transporter = 'Service visit';
            $this->vehicleNumber = 'WALK-IN';
            $this->driverName = 'Amit Sharma';
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
            $this->vendorName = 'Thermocore Materials Pvt Ltd';
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
        $rules = match ($this->entryType) {
            'visitor' => ['driverName' => ['required', 'string'], 'driverPhone' => ['required', 'string'], 'poNumber' => ['required', 'string'], 'material' => ['required', 'string']],
            'inward' => ['driverName' => ['required', 'string'], 'driverPhone' => ['required', 'string'], 'vehicleNumber' => ['required', 'string'], 'invoiceNumber' => ['required', 'string'], 'invoiceAmount' => ['required', 'numeric']],
            default => ['vehicleNumber' => ['required', 'string'], 'driverName' => ['required', 'string'], 'poNumber' => ['required', 'string'], 'vendorName' => ['required', 'string'], 'invoiceNumber' => ['required', 'string']]
        };
        $this->validate($rules);

        if ($this->entryType === 'inward' && ! $this->fetched) {
            $this->addError('invoiceNumber', 'Fetch the bill details for this bill number before saving.');

            return;
        }

        if (! $this->gps) {
            $this->useGps();
        }

        $selected = self::LOCATIONS[$this->location] ?? ['name' => $this->location, 'code' => 'NA'];
        $qty = $this->invoiceQty !== '' ? $this->invoiceQty : '1';
        $form = [
            'entry_type' => $this->entryType,
            'po_number' => $this->poNumber,
            'po_bill_date' => $this->poBillDate ?: null,
            'vendor_name' => $this->vendorName ?: ($this->entryType === 'visitor' ? ucfirst($this->entryType) : null),
            'vendor_gst' => $this->vendorGst ?: null,
            'invoice_number' => $this->invoiceNumber ?: ($this->entryType === 'visitor' ? strtoupper(substr($this->entryType, 0, 3)).'-'.now()->format('ymdHis') : null),
            'invoice_qty' => $qty,
            'invoice_amount' => $this->invoiceAmount !== '' ? $this->invoiceAmount : null,
            'rate' => $this->rate !== '' ? $this->rate : null,
            'material' => $this->material ?: 'N/A',
            'vehicle_number' => $this->vehicleNumber ?: 'WALK-IN',
            'driver_name' => $this->driverName,
            'transporter' => $this->transporter ?: null,
            'location' => $selected['name'],
            'gps' => $this->gps ?: null,
        ];

        $issues = app(GateValidationService::class)->validate($form);
        $blocking = app(GateValidationService::class)->isBlocking($issues);
        $gate = GateEntry::create([
            ...$form,
            'gate_no' => 'GATE-'.random_int(1000, 9999),
            'bill_scanned' => $this->entryType === 'visitor' ? false : $this->billScanned,
            'remarks' => trim($this->remarks."\nDocuments: ".$this->documentSummary()."\nLine: ".$this->material.' x '.$qty) ?: null,
            'status' => $blocking ? 'pending_validation' : 'validated',
            'sla_deadline' => now()->addHours(12),
        ]);

        foreach ($issues as $issue) {
            ValidationIssue::create([...$issue, 'gate_entry_id' => $gate->id, 'status' => 'open']);
        }

        AuditLogger::log($this->modeLabel().' entry created', "{$gate->gate_no} - {$selected['code']} - {$gate->vendor_name}", $gate);
        $this->saved = ['gate' => $gate, 'issues' => $issues, 'location' => $selected];
    }

    public function resetForm(): void
    {
        $currentType = $this->entryType;
        $this->billScanned = false;
        $this->ocrReady = false;
        $this->gps = '';
        $this->saved = null;
        $this->fetched = false;
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

        return collect($this->documents)->filter()->keys()->map(fn ($key) => $labels[$key] ?? $key)->join(', ') ?: 'No documents checked';
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
            <h1 class="text-2xl font-bold mt-4" style="color: var(--text-primary);">Entry saved</h1>
            <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $saved['gate']->gate_no }} - {{ $saved['location']['code'] }}</p>

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
                    <div class="font-semibold mt-1" style="color: var(--text-primary);">{{ $saved['gate']->entry_type === 'inward' ? 'Send to unloading' : ($saved['gate']->entry_type === 'outward' ? 'Confirm exit' : 'Gate pass active') }}</div>
                    <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ count($saved['issues']) ? count($saved['issues']).' issue(s)' : 'No blocking issue' }}</div>
                </div>
            </div>

            @if (count($saved['issues']))
                <div class="rounded-xl p-4 mt-5 text-left text-sm" style="background: var(--status-critical-bg); color: var(--status-critical);">
                    @foreach ($saved['issues'] as $issue)
                        <div><strong>{{ $issue['title'] }}</strong> - {{ $issue['description'] }}</div>
                    @endforeach
                </div>
            @else
                <div class="rounded-xl p-4 mt-5 text-sm" style="background: var(--status-good-bg); color: var(--status-good);">Saved successfully. No blocking issue found.</div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-5">
                <button wire:click="resetForm" class="rounded-xl px-4 py-3 text-sm font-semibold border" style="border-color: var(--border); color: var(--text-primary);">New {{ strtolower($modeLabel) }}</button>
                <a href="{{ $saved['gate']->entry_type === 'inward' ? route('unloading.dashboard') : route('guard.entries') }}" class="rounded-xl px-4 py-3 text-sm font-semibold text-white text-center" style="background: var(--brand);">{{ $saved['gate']->entry_type === 'inward' ? 'Send to Unloading' : 'View Entries' }}</a>
                <a href="{{ route('guard.entries') }}" wire:navigate class="rounded-xl px-4 py-3 text-sm font-semibold border text-center" style="border-color: var(--border); color: var(--text-primary);">Guard Entries</a>
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
        </section>


        <div class="grid grid-cols-1 xl:grid-cols-[1fr_340px] gap-5">
            <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex flex-col md:flex-row gap-3 md:items-center md:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold" style="color: var(--text-primary);">{{ $entryType === 'visitor' ? 'Visitor Pass' : 'Scan / Autofill' }}</h2>
                        <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $entryType === 'visitor' ? 'No bill upload for visitor entry.' : 'Use quick scan, or upload/capture bill if needed.' }}</p>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <button type="button" wire:click="fillSample" class="rounded-xl px-4 py-3 text-sm font-bold text-white" style="background: var(--brand);">{{ $entryType === 'visitor' ? 'Quick Visitor Fill' : 'Quick Scan Autofill' }}</button>
                        @unless ($entryType === 'visitor')
                            <label class="rounded-xl px-4 py-3 text-sm font-semibold border cursor-pointer text-center" style="border-color: var(--border); color: var(--text-primary);">
                                Camera / Upload
                                <input type="file" accept="image/*,.pdf" capture="environment" class="hidden" wire:click="$set('billScanned', true)" />
                            </label>
                        @endunless
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-5">
                    @if ($entryType === 'visitor')
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Visitor Name</span>
                            <input wire:model="driverName" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Visitor name" />
                            @error('driverName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Mobile</span>
                            <input wire:model="driverPhone" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="+91 ..." />
                            @error('driverPhone') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Company</span>
                            <input wire:model="vendorName" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Company / agency" />
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Person To Meet</span>
                            <input wire:model="poNumber" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Store Manager" />
                            @error('poNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm md:col-span-2">
                            <span class="font-semibold" style="color: var(--text-primary);">Purpose</span>
                            <input wire:model="material" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Meeting / service / audit" />
                            @error('material') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                    @elseif ($entryType === 'inward')
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Driver Name</span>
                            <input wire:model="driverName" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Driver name" />
                            @error('driverName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Driver Phone Number</span>
                            <input wire:model="driverPhone" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="+91 ..." />
                            @error('driverPhone') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm md:col-span-2">
                            <span class="font-semibold" style="color: var(--text-primary);">Vehicle Number</span>
                            <input wire:model="vehicleNumber" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="MH 04 GT 5521" />
                            @error('vehicleNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>

                        <label class="space-y-1.5 text-sm md:col-span-2">
                            <span class="font-semibold" style="color: var(--text-primary);">Bill Number</span>
                            <div class="flex gap-2">
                                <input wire:model="invoiceNumber" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Bill number" />
                                <button type="button" wire:click="fetchBillDetails" class="shrink-0 rounded-xl px-4 py-2.5 text-sm font-bold text-white" style="background: var(--brand);">Fetch</button>
                            </div>
                            @error('invoiceNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>

                        @if ($fetched)
                            <div class="md:col-span-2 rounded-xl border p-3 text-xs" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--text-primary);">
                                Fetched from vendor submission — PO {{ $poNumber }} · {{ $vendorName }} · Qty {{ $invoiceQty }} · {{ $material }}
                            </div>
                        @endif

                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">PO Number</span>
                            <input wire:model="poNumber" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="PO RM 2627 0020" />
                            @error('poNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Vendor</span>
                            <input wire:model="vendorName" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Vendor" />
                            @error('vendorName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Bill Date</span>
                            <input wire:model="poBillDate" type="date" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" />
                            @error('poBillDate') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Bill Amount</span>
                            <input wire:model="invoiceAmount" type="number" step="0.01" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="29400" />
                            @error('invoiceAmount') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                    @else
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Package Number</span>
                            <input wire:model="poNumber" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="OUT RM 2627 0020" />
                            @error('poNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Delivery Address</span>
                            <input wire:model="vendorName" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Delivery address" />
                            @error('vendorName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm md:col-span-2">
                            <span class="font-semibold" style="color: var(--text-primary);">Invoice / Doc No</span>
                            <input wire:model="invoiceNumber" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Invoice number" />
                            @error('invoiceNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                    @endif

                    @if ($entryType !== 'inward')
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">{{ $entryType === 'visitor' ? 'Vehicle / Walk-in' : 'Vehicle Number' }}</span>
                            <input wire:model="vehicleNumber" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="{{ $entryType === 'visitor' ? 'WALK-IN' : 'MH 04 GT 5521' }}" />
                            @error('vehicleNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        @unless ($entryType === 'visitor')
                            <label class="space-y-1.5 text-sm">
                                <span class="font-semibold" style="color: var(--text-primary);">Driver Name</span>
                                <input wire:model="driverName" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Driver name" />
                                @error('driverName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            </label>
                        @endunless
                    @endif
                </div>

                @if ($entryType === 'outward')
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

                <label class="block mt-5 space-y-1.5 text-sm">
                    <span class="font-semibold" style="color: var(--text-primary);">Remarks</span>
                    <textarea wire:model="remarks" rows="2" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Optional note"></textarea>
                </label>
            </section>

            <aside class="space-y-5">
                <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
                    <h2 class="font-semibold" style="color: var(--text-primary);">Location</h2>
                    <select wire:model.live="location" class="mt-3 w-full rounded-xl border px-3 py-2.5 text-sm" style="border-color: var(--border); color: var(--text-primary); background: var(--surface-2);">
                        @foreach ($locationDetails as $key => $loc)
                            <option value="{{ $key }}">{{ $loc['name'] }}</option>
                        @endforeach
                    </select>
                    <div class="rounded-xl border p-3 mt-3 text-xs" style="border-color: var(--border); background: var(--surface-2); color: var(--text-secondary);">
                        <strong style="color: var(--text-primary);">{{ $selectedLocation['code'] }}</strong><br>
                        {{ $selectedLocation['contact'] }} - {{ $selectedLocation['phone'] }}<br>
                        {{ $selectedLocation['address'] }}
                    </div>
                    <button type="button" wire:click="useGps" class="mt-3 w-full rounded-xl border px-3 py-3 text-sm font-semibold" style="border-color: var(--border); color: var(--text-primary);">{{ $gps ?: 'Capture GPS' }}</button>
                </section>

                <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
                    <div class="grid grid-cols-2 gap-3 text-center">
                        <div class="rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2);">
                            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $entryType === 'visitor' ? 'Pass' : ($entryType === 'inward' ? ($fetched ? 'Yes' : 'No') : $documentCount.'/4') }}</div>
                            <div class="text-xs" style="color: var(--text-muted);">{{ $entryType === 'visitor' ? 'Type' : ($entryType === 'inward' ? 'Bill Fetched' : 'Docs') }}</div>
                        </div>
                        <div class="rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2);">
                            <div class="text-xl font-bold" style="color: var(--text-primary);">{{ $gps ? 'Yes' : 'No' }}</div>
                            <div class="text-xs" style="color: var(--text-muted);">GPS</div>
                        </div>
                    </div>
                    <button wire:click="saveEntry" class="mt-4 w-full rounded-xl px-4 py-3 text-sm font-bold text-white" style="background: var(--brand);">
                        {{ $entryType === 'visitor' ? 'Save Visitor Pass' : ($entryType === 'outward' ? 'Save Outward Entry' : 'Save and Send to Unloading') }}
                    </button>
                </section>
            </aside>
        </div>
    @endif
</div>
