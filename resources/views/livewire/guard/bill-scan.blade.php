<?php

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\ValidationIssue;
use App\Models\VendorSubmission;
use App\Services\AuditLogger;
use App\Services\GateValidationService;
use App\Services\ZohoService;
use App\Support\SlaDirectives;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public string $entryType = 'inward';
    public bool $billScanned = false;
    public bool $ocrReady = false;

    #[Validate('nullable|file|mimes:jpg,jpeg,png,pdf|max:10240')]
    public $billFile = null;
    public string $gps = '';
    public ?array $saved = null;
    public bool $fetched = false;
    public string $fetchedSource = '';

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

    // Deliberately separate from driverName/poNumber/material rather than
    // reusing them under a different label: those three are shared across
    // inward/outward/visitor, which is exactly what made "Visitor Name" and
    // "Person To Meet" feel linked to the driver/PO fields for anyone
    // switching between entry types -- they were, literally, the same
    // underlying property.
    public string $visitorName = '';
    public string $visitorHostId = '';
    public string $visitPurpose = '';

    public array $visitPurposeOptions = ['Meeting', 'Service / Maintenance Visit', 'Audit / Inspection'];

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
            'hostOptions' => $this->entryType === 'visitor'
                ? User::whereNotNull('role')->where('role', '!=', Role::Vendor)->orderBy('name')->get(['id', 'name', 'role'])
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

    /** Inward only — the guard keys in the bill number and everything else
     * (PO number, vendor, quantity, material) is pulled from the vendor's
     * own portal submission for that bill instead of being typed twice. */
    public function fetchBillDetails(): void
    {
        $this->resetErrorBag('invoiceNumber');
        $this->validateOnly('invoiceNumber', ['invoiceNumber' => ['required', 'string']]);

        $submission = VendorSubmission::where('invoice_number', $this->invoiceNumber)->latest()->first();

        if ($submission) {
            $this->poNumber = $submission->po_number;
            $this->vendorName = $submission->vendor_name;
            $this->invoiceQty = (string) $submission->invoice_qty;
            $this->material = $submission->material;
            $this->fetchedSource = 'vendor submission';
            $this->fetched = true;

            return;
        }

        // The guard often has the PO number itself in hand (printed on the
        // vendor's own paperwork), not just an invoice number — check our
        // own PO Master before ever reaching out to Zoho. Previously this
        // was skipped entirely, so a PO that was already sitting in Admin ->
        // PO Master (synced or manually entered) was invisible here the
        // moment Zoho itself was slow, down, or the token needed refreshing.
        $localPo = PurchaseOrder::with('lines')->where('po_number', $this->invoiceNumber)->first();

        if ($localPo) {
            $line = $localPo->primaryLine();
            $this->poNumber = $localPo->po_number;
            $this->vendorName = $localPo->vendor_name;
            $this->invoiceQty = (string) ($line?->quantity ?? 1);
            $this->rate = (string) ($line?->list_price ?? 0);
            $this->material = $line?->product ?? 'Purchase Order Item';
            // Confirmed live (GATE-9863): what was typed here matched on
            // po_number, not invoice_number -- leaving it in place meant
            // invoice_number === po_number got saved, which then tripped a
            // false "Duplicate Invoice" hardFail against any other entry
            // that legitimately used this PO number as ITS invoice number.
            // Clear it so the guard enters the real invoice/bill number.
            $this->invoiceNumber = '';
            $this->fetchedSource = 'PO Master';
            $this->fetched = true;

            return;
        }

        $po = app(ZohoService::class)->syncPurchaseOrder($this->invoiceNumber);

        if (! $po) {
            $this->fetched = false;
            $this->fetchedSource = '';
            $this->addError('invoiceNumber', 'No vendor submission, PO Master record, or Zoho PO found for this number.');

            return;
        }

        $line = $po->primaryLine();
        $this->poNumber = $po->po_number;
        $this->vendorName = $po->vendor_name;
        $this->invoiceQty = (string) ($line?->quantity ?? 1);
        $this->rate = (string) ($line?->list_price ?? 0);
        $this->material = $line?->product ?? 'Zoho Purchase Item';
        // Same reasoning as the PO Master branch above: syncPurchaseOrder()
        // matched this as a PO number, not a real invoice number.
        $this->invoiceNumber = '';
        $this->fetchedSource = 'Zoho CRM';
        $this->fetched = true;
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
            'visitor' => ['visitorName' => ['required', 'string'], 'driverPhone' => ['required', 'string'], 'visitorHostId' => ['required', 'integer', 'exists:users,id'], 'visitPurpose' => ['required', 'string']],
            'inward' => ['driverName' => ['required', 'string'], 'driverPhone' => ['required', 'string'], 'vehicleNumber' => ['required', 'string'], 'invoiceNumber' => ['required', 'string'], 'invoiceAmount' => ['required', 'numeric'], 'poNumber' => ['required', 'string'], 'vendorName' => ['required', 'string'], 'material' => ['required', 'string']],
            default => ['vehicleNumber' => ['required', 'string'], 'driverName' => ['required', 'string'], 'poNumber' => ['required', 'string'], 'vendorName' => ['required', 'string'], 'invoiceNumber' => ['required', 'string']]
        };
        $this->validate($rules);

        // Deliberately NOT a hard block on !fetched: a guard must still be able
        // to log a truck whose PO genuinely isn't in the system yet (new
        // vendor, PO not entered/synced yet) -- that's exactly what
        // GateValidationService's PO_NOT_FOUND hardFail + pending_validation
        // routing already exists to handle. Blocking here entirely prevented
        // that fallback from ever being reached, which was a real regression:
        // the guard had no way to save the entry at all in that case.

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
        $poNumber = $isVisitor ? ($hostUser?->name ?? '') : $this->poNumber;
        $material = $isVisitor ? $this->visitPurpose : $this->material;

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
            'vehicle_number' => $this->vehicleNumber ?: 'WALK-IN',
            'driver_name' => $driverName,
            'transporter' => $this->transporter ?: null,
            'location' => $selected['name'],
            'gps' => $this->gps ?: null,
        ];

        $issues = app(GateValidationService::class)->validate($form);
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
        $documentPath = $this->billFile ? $this->billFile->store('gate-bills') : null;
        $gate = GateEntry::create([
            ...$form,
            'created_by' => auth()->id(),
            'visitor_host_id' => $hostUser?->id,
            'gate_no' => 'GATE-'.random_int(1000, 9999),
            'bill_scanned' => $isVisitor ? false : $this->billScanned,
            'bill_document_path' => $documentPath,
            'remarks' => trim($this->remarks."\nDocuments: ".$this->documentSummary()."\nLine: ".$material.' x '.$qty) ?: null,
            'status' => ($needsApproval || $blocking) ? 'pending_validation' : 'validated',
            'sla_deadline' => now()->addHours(SlaDirectives::hours($vendorUser?->sla_directive)),
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
        $this->billFile = null;
        $this->ocrReady = false;
        $this->gps = '';
        $this->saved = null;
        $this->fetched = false;
        $this->fetchedSource = '';
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
            <section class="rounded-2xl border p-5" x-data="{ scanning: false, scanMessage: '' }" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex flex-col md:flex-row gap-3 md:items-center md:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold" style="color: var(--text-primary);">{{ $entryType === 'visitor' ? 'Visitor Pass' : 'Scan / Autofill' }}</h2>
                        <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $entryType === 'visitor' ? 'No bill upload for visitor entry.' : 'Use quick scan, or upload/capture bill if needed.' }}</p>
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
                            <span class="font-semibold" style="color: var(--text-primary);">Bill / Zoho PO Number</span>
                            <div class="flex gap-2">
                                <input wire:model="invoiceNumber" id="invoiceNumber" name="invoiceNumber" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Bill number or 898897889" />
                                <button type="button" wire:click="fetchBillDetails" class="shrink-0 rounded-xl px-4 py-2.5 text-sm font-bold text-white" style="background: var(--brand);">Fetch</button>
                            </div>
                            @error('invoiceNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>

                        @if ($fetched)
                            <div class="md:col-span-2 rounded-xl border p-3 text-xs" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--text-primary);">
                                @if ($fetchedSource)
                                    <div>Source: {{ $fetchedSource }}</div>
                                @endif
                                Matched — PO {{ $poNumber }} · {{ $vendorName }} · Qty {{ $invoiceQty }} · {{ $material }}
                                @if (in_array($fetchedSource, ['PO Master', 'Zoho CRM'], true))
                                    <div class="mt-1 font-semibold">What you searched with was a PO number, not a bill number — the field above is now cleared. Type the actual invoice/bill number from the paper bill before saving.</div>
                                @endif
                            </div>
                        @endif

                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">PO Number</span>
                            <input wire:model="poNumber" id="poNumber" name="poNumber" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="PO RM 2627 0020" />
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
                    @else
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Package Number</span>
                            <input wire:model="poNumber" id="poNumber" name="poNumber" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="OUT RM 2627 0020" />
                            @error('poNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm">
                            <span class="font-semibold" style="color: var(--text-primary);">Delivery Address</span>
                            <input wire:model="vendorName" id="vendorName" name="vendorName" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Delivery address" />
                            @error('vendorName') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <label class="space-y-1.5 text-sm md:col-span-2">
                            <span class="font-semibold" style="color: var(--text-primary);">Invoice / Doc No</span>
                            <input wire:model="invoiceNumber" id="invoiceNumber" name="invoiceNumber" autocomplete="off" class="w-full rounded-xl border px-3 py-2.5" style="border-color: var(--border);" placeholder="Invoice number" />
                            @error('invoiceNumber') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
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

                    <button wire:click="saveEntry" class="mt-4 w-full rounded-xl px-4 py-3 text-sm font-bold text-white" style="background: var(--brand);">
                        {{ $entryType === 'visitor' ? 'Save Visitor Pass' : ($entryType === 'outward' ? 'Save Outward Entry' : 'Save and Send to Unloading') }}
                    </button>
                </section>
            </aside>
        </div>
    @endif
</div>
