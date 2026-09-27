<?php

use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Services\QcService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public ?int $checking = null;

    public ?int $resolvingHold = null;

    public string $holdAccepted = '0';

    public string $holdRejected = '0';

    public string $holdResolutionNote = '';

    public array $holdLineDecisions = [];

    public string $accepted = '';

    public string $qcHold = '';

    public string $defective = '';

    public string $rejected = '';

    public string $physicalReceived = '';

    public string $invoiceAmount = '';

    public string $unitPrice = '';

    public string $qcReasons = '';

    public string $holdReason = '';

    public $holdDocument = null;

    public array $evidenceImages = [];

    public array $evidenceImageTypes = [];

    /** @var array<int, array{name: string, value: string, result: string}> */
    public array $parameters = [];

    /** @var array<string, bool> */
    public array $documentsChecked = ['invoice' => false, 'eway' => false, 'lr' => false, 'pod' => false];

    /** @var array<int, array<string, mixed>> */
    public array $lineSplits = [];

    public bool $multiSku = false;

    public function openCheck(int $gateId): void
    {
        $gate = GateEntry::with('unloadingRecord')->findOrFail($gateId);
        $purchaseOrder = PurchaseOrder::with('lines')->where('po_number', $gate->po_number)->first();
        $sourceLines = collect(is_array($gate->product_lines) ? $gate->product_lines : [])
            ->filter(fn ($line) => is_array($line) && filled($line['sku'] ?? $line['name'] ?? $line['material'] ?? null))
            ->values();

        if ($sourceLines->count() < 2 && $purchaseOrder?->lines?->count() > 1) {
            $sourceLines = $purchaseOrder->lines->map(fn ($line) => [
                'sku' => $line->product,
                'qty' => $line->quantity,
                'unit_price' => $line->list_price,
            ])->values();
        }

        $this->multiSku = $sourceLines->count() > 1;
        $this->lineSplits = $this->multiSku
            ? $sourceLines->map(function (array $line): array {
                $sku = (string) ($line['sku'] ?? $line['name'] ?? $line['material']);
                $qty = (int) ($line['invoice_qty'] ?? $line['qty'] ?? $line['quantity'] ?? 0);
                $price = (float) ($line['unit_price'] ?? $line['rate'] ?? 0);

                return [
                    'sku' => $sku,
                    'poQty' => (int) ($line['po_qty'] ?? $line['po_quantity'] ?? $qty),
                    'invoiceQty' => $qty,
                    'physicalReceived' => $qty,
                    'accepted' => $qty,
                    'qcHold' => 0,
                    'defective' => 0,
                    'rejected' => 0,
                    'invoiceAmount' => $price * $qty,
                    'unitPrice' => $price,
                ];
            })->all()
            : [];

        $line = $purchaseOrder?->primaryLine();
        $this->checking = $gateId;
        $this->reset(['accepted', 'qcHold', 'defective', 'rejected', 'qcReasons', 'holdReason', 'holdDocument', 'parameters', 'evidenceImages', 'evidenceImageTypes']);
        $this->evidenceImages = [null, null];
        $this->evidenceImageTypes = ['Defective Image', 'Rejected Image'];
        $totalPhysical = $this->multiSku ? collect($this->lineSplits)->sum('physicalReceived') : (int) ($gate->invoice_qty ?? 0);
        $totalAmount = $this->multiSku ? collect($this->lineSplits)->sum('invoiceAmount') : (($line?->list_price ?? $gate->rate ?? 0) * ($gate->invoice_qty ?? 0));
        $this->physicalReceived = (string) $totalPhysical;
        $this->invoiceAmount = (string) ($gate->invoice_amount ?? $totalAmount);
        $this->unitPrice = (string) ($gate->rate ?? $line?->list_price ?? 0);
        $this->parameters = [
            ['name' => 'Quantity', 'value' => $this->physicalReceived, 'result' => 'Pass'],
            ['name' => 'Invoice / PO Amount', 'value' => $this->invoiceAmount, 'result' => 'Pass'],
            ['name' => 'Unit Price', 'value' => $this->unitPrice, 'result' => 'Pass'],
        ];
        $this->documentsChecked = ['invoice' => false, 'eway' => false, 'lr' => false, 'pod' => false];
    }

    public function addEvidenceImage(): void
    {
        if (count($this->evidenceImages) >= 10) {
            $this->addError('evidenceImages', 'A maximum of 10 evidence images can be attached to one QC line.');

            return;
        }

        $index = count($this->evidenceImages);
        $this->evidenceImages[$index] = null;
        $this->evidenceImageTypes[$index] = 'Defective Image';
    }

    public function removeEvidenceImage(int $index): void
    {
        if (! array_key_exists($index, $this->evidenceImages)) {
            return;
        }

        unset($this->evidenceImages[$index], $this->evidenceImageTypes[$index]);
        $this->evidenceImages = array_values($this->evidenceImages);
        $this->evidenceImageTypes = array_values($this->evidenceImageTypes);
    }

    public function addParameter(): void
    {
        $this->parameters[] = ['name' => '', 'value' => '', 'result' => 'Pass'];
    }

    public function removeParameter(int $index): void
    {
        if ($index < 3) {
            return;
        }

        unset($this->parameters[$index]);
        $this->parameters = array_values($this->parameters);
    }

    public function submitCheck(): void
    {
        foreach (['qcHold', 'defective', 'rejected'] as $optionalQuantity) {
            if (trim((string) $this->{$optionalQuantity}) === '') {
                $this->{$optionalQuantity} = '0';
            }
        }

        $multiHold = $this->multiSku && collect($this->lineSplits)->sum('qcHold') > 0;
        $multiDefective = $this->multiSku && collect($this->lineSplits)->sum('defective') > 0;
        $multiRejected = $this->multiSku && collect($this->lineSplits)->sum('rejected') > 0;
        $evidenceRequired = $this->multiSku
            ? $multiDefective || $multiRejected
            : (int) $this->defective > 0 || (int) $this->rejected > 0;
        $hasEvidenceUploads = collect($this->evidenceImages)->contains(fn ($image) => $image !== null && $image !== '');
        $rules = [
            'accepted' => ['required', 'integer', 'min:0'],
            'qcHold' => ['required', 'integer', 'min:0'],
            'defective' => ['required', 'integer', 'min:0'],
            'rejected' => ['required', 'integer', 'min:0'],
            'physicalReceived' => ['required', 'integer', 'min:1'],
            'invoiceAmount' => ['required', 'numeric', 'min:0'],
            'unitPrice' => ['required', 'numeric', 'min:0'],
            'holdReason' => [($this->qcHold > 0 || $multiHold) ? 'required' : 'nullable', 'string', 'max:1000'],
            'holdDocument' => ['nullable', 'file', 'max:10240'],
            'parameters.*.name' => ['required_with:parameters.*.value', 'nullable', 'string', 'max:255'],
            'parameters.*.value' => ['required_with:parameters.*.name', 'nullable', 'string', 'max:255'],
        ];
        if ($this->multiSku) {
            unset($rules['accepted'], $rules['qcHold'], $rules['defective'], $rules['rejected'], $rules['invoiceAmount'], $rules['unitPrice']);
            $rules['lineSplits'] = ['required', 'array', 'min:2'];
            foreach (['sku' => ['required', 'string', 'max:255'], 'poQty' => ['required', 'integer', 'min:0'], 'invoiceQty' => ['required', 'integer', 'min:0'], 'physicalReceived' => ['required', 'integer', 'min:1'], 'accepted' => ['required', 'integer', 'min:0'], 'qcHold' => ['required', 'integer', 'min:0'], 'defective' => ['required', 'integer', 'min:0'], 'rejected' => ['required', 'integer', 'min:0'], 'invoiceAmount' => ['required', 'numeric', 'min:0'], 'unitPrice' => ['required', 'numeric', 'min:0']] as $field => $rule) {
                $rules["lineSplits.*.{$field}"] = $rule;
            }
        }
        if ($evidenceRequired || $hasEvidenceUploads) {
            $rules['evidenceImages'] = ['required', 'array', 'max:10'];
            $rules['evidenceImages.*'] = ['nullable', 'image', 'max:5120'];
            $rules['evidenceImageTypes'] = ['required', 'array'];
            $rules['evidenceImageTypes.*'] = ['required', 'in:Defective Image,Rejected Image'];
        }
        $this->validate($rules, [
            'evidenceImages.*.image' => 'Choose a valid image file (JPG, PNG, or WebP).',
            'evidenceImages.*.max' => 'Each QC evidence image must be 5 MB or smaller.',
        ]);

        $attachedImages = collect($this->evidenceImages)
            ->filter(fn ($image) => $image !== null && $image !== '')
            ->all();
        if ($evidenceRequired && count($attachedImages) < 2) {
            $this->addError('evidenceImages', 'Attach at least 2 images before sending defective or rejected quantity to GRN Check.');

            return;
        }
        $attachedTypes = collect($attachedImages)->map(fn ($image, $index) => $this->evidenceImageTypes[$index] ?? null)->all();
        if ((int) $this->defective > 0 && ! in_array('Defective Image', $attachedTypes, true)) {
            $this->addError('evidenceImages', 'At least one uploaded image must be tagged Defective Image for defective quantity.');

            return;
        }
        if ((int) $this->rejected > 0 && ! in_array('Rejected Image', $attachedTypes, true)) {
            $this->addError('evidenceImages', 'At least one uploaded image must be tagged Rejected Image for rejected quantity.');

            return;
        }

        $parameterRows = array_values(array_filter($this->parameters, fn ($p) => trim($p['name'] ?? '') !== ''));
        $requiredParameterNames = ['Quantity', 'Invoice / PO Amount', 'Unit Price'];
        $recordedParameterNames = collect($parameterRows)->pluck('name')->map(fn ($name) => trim($name))->all();
        if (array_diff($requiredParameterNames, $recordedParameterNames) !== []) {
            $this->addError('parameters', 'Quantity, Invoice / PO Amount and Unit Price are mandatory QC parameters.');

            return;
        }

        $gate = GateEntry::findOrFail($this->checking);
        $physicalReceived = (int) $this->physicalReceived;
        $received = $this->multiSku
            ? collect($this->lineSplits)->sum(fn (array $line) => (int) $line['accepted'] + (int) $line['qcHold'] + (int) $line['defective'] + (int) $line['rejected'])
            : (int) $this->accepted + (int) $this->qcHold + (int) $this->defective + (int) $this->rejected;
        if ($received !== $physicalReceived) {
            $this->addError('accepted', "Accepted + hold + defective + rejected must equal total physical quantity received ({$physicalReceived}).");

            return;
        }

        $holdDocumentPath = $this->holdDocument?->store('qc-hold-documents', 'local');
        $evidenceImageRecords = collect($attachedImages)->map(function ($image, $index) {
            return [
                'path' => $image->store('qc-evidence', 'local'),
                'type' => $this->evidenceImageTypes[$index] ?? 'Defective Image',
            ];
        })->values()->all();

        if ($this->multiSku) {
            $pricedLineSplits = collect($this->lineSplits)->map(function (array $line): array {
                $line['invoiceAmount'] = round((int) $line['invoiceQty'] * (float) $line['unitPrice'], 2);

                return $line;
            })->all();

            app(QcService::class)->recordMultiResult(
                $gate,
                $pricedLineSplits,
                $this->qcReasons ?: null,
                $this->holdReason ?: null,
                $holdDocumentPath,
                $parameterRows,
                $this->documentsChecked,
                $evidenceImageRecords ?: null,
            );
        } else {
            $invoiceQty = (int) ($gate->invoice_qty ?? $physicalReceived);
            $poQty = (int) (PurchaseOrder::with('lines')->where('po_number', $gate->po_number)->first()?->lines->sum('quantity') ?? $invoiceQty);
            app(QcService::class)->recordResult($gate, $gate->material, $poQty, $invoiceQty, [
                'accepted' => (int) $this->accepted, 'qcHold' => (int) $this->qcHold,
                'defective' => (int) $this->defective, 'rejected' => (int) $this->rejected,
            ], $this->qcReasons ?: null, $this->holdReason ?: null, $holdDocumentPath, $parameterRows,
                $this->documentsChecked, (float) $this->invoiceAmount, (float) $this->unitPrice,
                $evidenceImageRecords ?: null, null, (int) $this->physicalReceived);
        }

        session()->flash('success', "{$gate->gate_no} QC submitted and sent to GRN Check.");
        $this->reset(['checking', 'accepted', 'qcHold', 'defective', 'rejected', 'physicalReceived', 'invoiceAmount', 'unitPrice', 'qcReasons', 'holdReason', 'holdDocument', 'parameters', 'documentsChecked', 'evidenceImages', 'evidenceImageTypes', 'lineSplits', 'multiSku', 'holdLineDecisions']);
    }

    public function openResolveHold(int $qcResultId): void
    {
        $result = \App\Models\QcResult::with(['gateEntry', 'lines'])->findOrFail($qcResultId);
        if ((int) $result->qc_hold_qty <= 0 || in_array($result->gateEntry?->status, ['grn_posted', 'closed'], true)) {
            return;
        }

        $this->resolvingHold = $result->id;
        $this->holdAccepted = '0';
        $this->holdRejected = (string) $result->qc_hold_qty;
        $this->holdResolutionNote = '';
        $this->holdLineDecisions = $result->lines->mapWithKeys(fn ($line) => [$line->id => [
            'sku' => $line->sku,
            'hold' => (int) $line->qc_hold_qty,
            'accepted' => 0,
            'rejected' => (int) $line->qc_hold_qty,
        ]])->all();
    }

    public function cancelResolveHold(): void
    {
        $this->reset(['resolvingHold', 'holdAccepted', 'holdRejected', 'holdResolutionNote', 'holdLineDecisions']);
    }

    public function resolveHold(): void
    {
        $result = \App\Models\QcResult::with(['gateEntry', 'lines'])->findOrFail($this->resolvingHold);
        $this->validate([
            'holdAccepted' => ['required', 'integer', 'min:0'],
            'holdRejected' => ['required', 'integer', 'min:0'],
            'holdResolutionNote' => ['required', 'string', 'max:1000'],
        ]);

        $hasLineDecisions = $result->lines->isNotEmpty();
        if ($hasLineDecisions) {
            $lineAccepted = 0;
            $lineRejected = 0;
            foreach ($result->lines->where('qc_hold_qty', '>', 0) as $line) {
                $decision = $this->holdLineDecisions[$line->id] ?? [];
                $accepted = (int) ($decision['accepted'] ?? 0);
                $rejected = (int) ($decision['rejected'] ?? 0);
                if ($accepted < 0 || $rejected < 0 || $accepted + $rejected !== (int) $line->qc_hold_qty) {
                    $this->addError('holdLineDecisions', "{$line->sku}: accepted + rejected must equal held quantity ({$line->qc_hold_qty}).");
                    return;
                }
                $lineAccepted += $accepted;
                $lineRejected += $rejected;
            }
            $this->holdAccepted = (string) $lineAccepted;
            $this->holdRejected = (string) $lineRejected;
        }

        $resolved = (int) $this->holdAccepted + (int) $this->holdRejected;
        if ($resolved !== (int) $result->qc_hold_qty) {
            $this->addError('holdAccepted', "Accepted from hold + rejected from hold must equal the held quantity ({$result->qc_hold_qty}).");

            return;
        }

        if ($hasLineDecisions) {
            foreach ($result->lines->where('qc_hold_qty', '>', 0) as $line) {
                $decision = $this->holdLineDecisions[$line->id];
                $line->update([
                    'accepted_qty' => (int) $line->accepted_qty + (int) $decision['accepted'],
                    'rejected_qty' => (int) $line->rejected_qty + (int) $decision['rejected'],
                    'qc_hold_qty' => 0,
                    'hold_reason' => trim(($line->hold_reason ? $line->hold_reason."\n" : '').'Hold resolved: '.$this->holdResolutionNote),
                ]);
            }
        }

        $result->update([
            'accepted_qty' => (int) $result->accepted_qty + (int) $this->holdAccepted,
            'rejected_qty' => (int) $result->rejected_qty + (int) $this->holdRejected,
            'qc_hold_qty' => 0,
            'hold_reason' => trim(($result->hold_reason ? $result->hold_reason."\n" : '').'Hold resolved: '.$this->holdResolutionNote),
            'return_status' => ((int) $result->rejected_qty + (int) $this->holdRejected) > 0 ? 'pending' : $result->return_status,
            'return_requested_at' => ((int) $result->rejected_qty + (int) $this->holdRejected) > 0 ? ($result->return_requested_at ?? now()) : $result->return_requested_at,
        ]);

        if ($result->gateEntry && $result->gateEntry->status === 'grn') {
            $result->gateEntry->update(['status' => 'qc_done']);
        }

        \App\Services\AuditLogger::log('QC hold resolved', "{$result->gateEntry?->gate_no} · accepted {$this->holdAccepted}, rejected {$this->holdRejected}", $result);
        session()->flash('success', "QC hold resolved for {$result->gateEntry?->gate_no}. GRN can now show final accepted/rejected quantities.");
        $this->cancelResolveHold();
    }

    public function with(): array
    {
        return [
            'queue' => GateEntry::where('status', 'grn')->orderByDesc('created_at')->get(),
            'holdQueue' => \App\Models\QcResult::with(['gateEntry', 'lines'])
                ->where('qc_hold_qty', '>', 0)
                ->whereHas('gateEntry', fn ($q) => $q->whereNotIn('status', ['grn_posted', 'closed']))
                ->latest()
                ->get(),
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">QC Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">QC Queue</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Split each delivery into accepted / hold / defective / rejected before it can go to GRN Check.</p>
    </section>

    @if (session('success'))
        <div role="status" aria-live="polite" class="rounded-xl border px-4 py-3 mb-4 text-sm font-medium" style="background: var(--status-good-bg); border-color: var(--status-good); color: var(--status-good);">{{ session('success') }}</div>
    @endif

    <div class="flex flex-col gap-3">
        @forelse ($queue as $g)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <a href="{{ route('gate-entries.show', $g) }}" wire:navigate class="hover:opacity-80">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ count($g->product_lines ?? []) > 1 ? 'Multi-SKU shipment · separate quantity per SKU below' : $g->material.' · Invoice qty '.$g->invoice_qty }}</div>
                        @if (count($g->product_lines ?? []) > 1)
                            <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs" style="color: var(--text-secondary);">
                                @foreach ($g->product_lines as $line)
                                    <span>{{ $line['name'] ?? $line['sku'] }}: {{ $line['qty'] ?? '—' }} {{ $line['uom'] ?? '' }}</span>
                                @endforeach
                            </div>
                        @elseif (($g->product_lines[0]['uom'] ?? '') === 'Mixed / review invoice')
                            <div role="note" class="mt-2 text-xs" style="color: var(--status-warning);">Legacy invoice has no per-SKU quantity split; use its bill as the reference during physical QC.</div>
                        @endif
                    </a>
                    @if ($checking !== $g->id)
                        <button wire:click="openCheck({{ $g->id }})" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white" style="background: var(--brand);">QC Check</button>
                    @endif
                </div>

                @if ($checking === $g->id)
                    @if ($errors->any())
                        <div class="mt-4 rounded-lg border p-3 text-xs" style="border-color: var(--status-critical); background: var(--status-critical-bg); color: var(--status-critical);">
                            Fix the highlighted field(s) below before submitting.
                        </div>
                    @endif
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
                        @if ($multiSku)
                            <div class="sm:col-span-4 rounded-lg border p-3 text-sm" style="border-color: var(--border); background: var(--surface-2);">
                                <div class="font-semibold" style="color:var(--text-primary);">Enter QC per SKU</div>
                                <p class="text-xs mt-1" style="color:var(--text-secondary);">Each row below keeps its own quantity and price. Quantities and values are not combined across different items.</p>
                            </div>
                        @else
                        <div class="sm:col-span-4 grid grid-cols-1 sm:grid-cols-3 gap-3 rounded-lg border p-3" style="border-color: var(--border); background: var(--surface-2);">
                            <label class="flex flex-col gap-1.5 text-sm">
                                <span class="font-medium" style="color: var(--text-primary);">Physical quantity received</span>
                                <input wire:model="physicalReceived" type="number" min="1" readonly class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-3);" />
                                <span class="text-xs" style="color: var(--text-muted);">Auto-filled from PO/invoice; split below must equal this.</span>
                            </label>
                            <label class="flex flex-col gap-1.5 text-sm">
                                <span class="font-medium" style="color: var(--text-primary);">Invoice / PO amount</span>
                                <input wire:model="invoiceAmount" type="number" step="0.01" min="0" readonly class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-3);" />
                                @error('invoiceAmount') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            </label>
                            <label class="flex flex-col gap-1.5 text-sm">
                                <span class="font-medium" style="color: var(--text-primary);">Unit price</span>
                                <input wire:model="unitPrice" type="number" step="0.0001" min="0" readonly class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-3);" />
                                @error('unitPrice') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            </label>
                            @error('physicalReceived') <span class="text-xs sm:col-span-3" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </div>
                        @endif
                        @if ($multiSku)
                            <div class="sm:col-span-4 rounded-lg border p-3" style="border-color: var(--brand); background: var(--surface-2);">
                                <div class="flex items-start justify-between gap-3 flex-wrap mb-3">
                                    <div>
                                        <div class="font-medium text-sm" style="color: var(--text-primary);">Multi-SKU QC split</div>
                                        <p class="text-xs mt-1" style="color: var(--text-secondary);">Each row is posted to its own SKU inventory. Do not combine quantities between rows.</p>
                                    </div>
                                    <span class="text-xs font-semibold" style="color: var(--brand);">{{ count($lineSplits) }} SKU lines</span>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full min-w-[1180px] text-xs">
                                        <thead><tr class="text-left" style="color: var(--text-muted); border-bottom: 1px solid var(--border);"><th class="py-2 pr-2">SKU / Item</th><th class="py-2 pr-2">PO qty</th><th class="py-2 pr-2">Invoice qty</th><th class="py-2 pr-2">Physical</th><th class="py-2 pr-2">Accepted</th><th class="py-2 pr-2">QC Hold</th><th class="py-2 pr-2">Defective</th><th class="py-2 pr-2">Rejected</th><th class="py-2 pr-2">Unit price</th><th class="py-2 pr-2">Line invoice value</th><th class="py-2 pr-2">Accepted value</th></tr></thead>
                                        <tbody>
                                            @foreach ($lineSplits as $i => $line)
                                                <tr wire:key="qc-line-{{ $g->id }}-{{ $i }}" style="border-top: 1px solid var(--border);">
                                                    <td class="py-2 pr-2 font-semibold" style="color: var(--text-primary);">{{ $line['sku'] }}</td>
                                                    <td class="py-2 pr-2">{{ $line['poQty'] }}</td>
                                                    <td class="py-2 pr-2">{{ $line['invoiceQty'] }}</td>
                                                    <td class="py-2 pr-2"><input wire:model="lineSplits.{{ $i }}.physicalReceived" type="number" min="1" class="w-24 rounded-lg border px-2 py-2" style="border-color: var(--border);" /></td>
                                                    <td class="py-2 pr-2"><input wire:model="lineSplits.{{ $i }}.accepted" type="number" min="0" class="w-24 rounded-lg border px-2 py-2" style="border-color: var(--border);" /></td>
                                                    <td class="py-2 pr-2"><input wire:model.live="lineSplits.{{ $i }}.qcHold" type="number" min="0" class="w-24 rounded-lg border px-2 py-2" style="border-color: var(--border);" /></td>
                                                    <td class="py-2 pr-2"><input wire:model.live="lineSplits.{{ $i }}.defective" type="number" min="0" class="w-24 rounded-lg border px-2 py-2" style="border-color: var(--border);" /></td>
                                                    <td class="py-2 pr-2"><input wire:model.live="lineSplits.{{ $i }}.rejected" type="number" min="0" class="w-24 rounded-lg border px-2 py-2" style="border-color: var(--border);" /></td>
                                                    <td class="py-2 pr-2"><input wire:model.live.debounce.250ms="lineSplits.{{ $i }}.unitPrice" type="number" step="0.0001" min="0" class="w-28 rounded-lg border px-2 py-2" style="border-color: var(--border);" /></td>
                                                    <td class="py-2 pr-2">₹{{ number_format((int) $line['invoiceQty'] * (float) $line['unitPrice'], 2) }}</td>
                                                    <td class="py-2 pr-2 font-semibold">₹{{ number_format((int) $line['accepted'] * (float) $line['unitPrice'], 2) }}</td>
                                                </tr>
                                                @error("lineSplits.{$i}.accepted") <tr><td colspan="11" class="py-1" style="color: var(--status-critical);">{{ $message }}</td></tr> @enderror
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                @error('lineSplits') <div class="text-xs mt-2" style="color: var(--status-critical);">{{ $message }}</div> @enderror
                            </div>
                        @else
                            <label class="flex flex-col gap-1.5 text-sm">
                                <span class="font-medium" style="color: var(--text-primary);">Accepted</span>
                                <input wire:model="accepted" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                                @error('accepted') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            </label>
                            <label class="flex flex-col gap-1.5 text-sm">
                                <span class="font-medium" style="color: var(--text-primary);">QC Hold</span>
                                <input wire:model.live="qcHold" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                                @error('qcHold') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            </label>
                            <label class="flex flex-col gap-1.5 text-sm">
                                <span class="font-medium" style="color: var(--text-primary);">Defective</span>
                                <input wire:model.live.debounce.150ms="defective" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                                @error('defective') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            </label>
                            <label class="flex flex-col gap-1.5 text-sm">
                                <span class="font-medium" style="color: var(--text-primary);">Rejected</span>
                                <input wire:model.live.debounce.150ms="rejected" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                                @error('rejected') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            </label>
                        @endif
                        <div class="sm:col-span-4">
                            <span class="font-medium text-sm" style="color: var(--text-primary);">Documents checked</span>
                            <div class="flex flex-wrap gap-3 mt-2">
                                @foreach (['invoice' => 'Invoice', 'eway' => 'E-way Bill', 'lr' => 'LR/LRC', 'pod' => 'POD'] as $key => $label)
                                    <label class="flex items-center gap-1.5 text-sm">
                                        <input type="checkbox" wire:model="documentsChecked.{{ $key }}" class="rounded" />
                                        <span style="color: var(--text-primary);">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div class="sm:col-span-4">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-medium text-sm" style="color: var(--text-primary);">Product parameters (required)</span>
                                <button type="button" wire:click="addParameter" class="text-xs font-medium" style="color: var(--brand);">+ Add parameter</button>
                            </div>
                            <div class="flex flex-col gap-2">
                                @foreach ($parameters as $i => $param)
                                    <div class="grid grid-cols-1 sm:grid-cols-[2fr_2fr_1fr_auto] gap-2 items-start">
                                        <input wire:model="parameters.{{ $i }}.name" placeholder="Parameter (e.g. Moisture %)" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                                        <input wire:model="parameters.{{ $i }}.value" placeholder="Observed value" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                                        <select wire:model="parameters.{{ $i }}.result" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-1); color: var(--text-primary);">
                                            <option value="Pass">Pass</option>
                                            <option value="Fail">Fail</option>
                                        </select>
                                        @if ($i >= 3)<button type="button" wire:click="removeParameter({{ $i }})" class="text-xs font-medium px-2 py-2" style="color: var(--status-critical);">Remove</button>@else<span class="text-xs px-2 py-2" style="color: var(--text-muted);">Mandatory</span>@endif
                                    </div>
                                    @error("parameters.{$i}.name") <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                                @endforeach
                                @if (empty($parameters))
                                    <p class="text-xs" style="color: var(--text-muted);">Add at least one physical/chemical spec checked (moisture, temperature, dimensions, etc.).</p>
                                @endif
                                @error('parameters') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <label class="flex flex-col gap-1.5 text-sm sm:col-span-4">
                            <span class="font-medium" style="color: var(--text-primary);">QC reasons (if any hold/defective/rejected)</span>
                            <textarea wire:model="qcReasons" rows="2" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);"></textarea>
                        </label>
                        <label class="flex flex-col gap-1.5 text-sm sm:col-span-2">
                            <span class="font-medium" style="color: var(--text-primary);">Quality Hold reason <span class="font-normal text-xs" style="color: var(--text-muted);">(required when QC Hold is above 0)</span></span>
                            <textarea wire:model="holdReason" rows="2" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Why is this quantity on hold?"></textarea>
                            @error('holdReason') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        @if ((int) $qcHold > 0)
                            <label class="flex flex-col gap-1.5 text-sm sm:col-span-2">
                                <span class="font-medium" style="color: var(--text-primary);">Attach hold document (optional)</span>
                                <input wire:model="holdDocument" type="file" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                                @error('holdDocument') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            </label>
                        @endif
                        @if ((int) $defective > 0 || (int) $rejected > 0 || collect($evidenceImages)->contains(fn ($image) => $image !== null && $image !== ''))
                        <div class="sm:col-span-4 rounded-lg border p-3" style="border-color: var(--border); background: var(--surface-2);">
                                <div class="flex items-center justify-between gap-3 mb-2">
                                    <div>
                                        <div class="font-medium text-sm" style="color: var(--text-primary);">QC line evidence images</div>
                                        <div class="text-xs mt-1" style="color: var(--text-muted);">Upload at least two images if any quantity is defective or rejected. Accepted-only checks and quality holds do not require images. Each image is tagged and saved against this QC record.</div>
                                    </div>
                                    <button type="button" wire:click="addEvidenceImage" class="text-xs font-semibold shrink-0" style="color: var(--brand);">+ Add image</button>
                                </div>
                                <div class="flex flex-col gap-2">
                                    @foreach ($evidenceImages as $i => $image)
                                        <div wire:key="qc-evidence-{{ $g->id }}-{{ $i }}" class="grid grid-cols-1 sm:grid-cols-[2fr_1fr_auto] gap-2 items-start">
                                            <label class="flex flex-col gap-1.5 text-xs">
                                                <span class="font-medium" style="color: var(--text-secondary);">Image {{ $i + 1 }} — camera or upload</span>
                                                <input wire:model="evidenceImages.{{ $i }}" type="file" accept="image/*" capture="environment" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-1);" />
                                                @if ($image)<span style="color: var(--text-muted);">Selected: {{ $image->getClientOriginalName() }}</span>@endif
                                                @error("evidenceImages.{$i}") <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                                            </label>
                                            <label class="flex flex-col gap-1.5 text-xs">
                                                <span class="font-medium" style="color: var(--text-secondary);">Image tag</span>
                                                <select wire:model="evidenceImageTypes.{{ $i }}" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border); background: var(--surface-1); color: var(--text-primary);">
                                                    <option value="Defective Image">Defective Image</option>
                                                    <option value="Rejected Image">Rejected Image</option>
                                                </select>
                                            </label>
                                            <button type="button" wire:click="removeEvidenceImage({{ $i }})" class="text-xs font-medium px-2 py-2" style="color: var(--status-critical);">Remove</button>
                                        </div>
                                    @endforeach
                                </div>
                                @error('evidenceImages') <div role="alert" class="text-xs mt-2 font-medium" style="color: var(--status-critical);">{{ $message }}</div> @enderror
                                @error('evidenceImageTypes.*') <div role="alert" class="text-xs mt-2" style="color: var(--status-critical);">{{ $message }}</div> @enderror
                        </div>
                        @else
                            <div class="sm:col-span-4 rounded-lg border p-3 text-xs" style="border-color: var(--border); color: var(--text-muted);">Photos are required only when defective or rejected quantity is entered. A QC hold requires a written reason and must be resolved before GRN posting.</div>
                        @endif
                        <button wire:click="submitCheck" class="sm:col-span-4 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Submit QC Check &amp; send to GRN Check</button>
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">Nothing waiting for QC.</div>
        @endforelse
    </div>

    <section class="mt-8 rounded-2xl border p-4" style="background: var(--surface-3); border-color: var(--status-warning);">
        <div class="flex items-start justify-between gap-3 flex-wrap mb-3">
            <div>
                <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Held quantities — resolve before GRN</h2>
                <p class="text-xs mt-1" style="color: var(--text-secondary);">QC can edit a hold into accepted or rejected. GRN posting remains blocked until the hold is zero.</p>
            </div>
        </div>
        <div class="flex flex-col gap-3">
            @forelse ($holdQueue as $result)
                <div wire:key="qc-hold-{{ $result->id }}" class="rounded-xl border p-4" style="border-color: var(--border); background: var(--surface-2);">
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <div>
                            <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $result->gateEntry?->gate_no }} · {{ $result->sku }}</div>
                            <div class="text-xs mt-1" style="color: var(--status-warning);">On hold: {{ $result->qc_hold_qty }} · Accepted: {{ $result->accepted_qty }} · Rejected: {{ $result->rejected_qty }}</div>
                        </div>
                        @if ($resolvingHold !== $result->id)
                            <button type="button" wire:click="openResolveHold({{ $result->id }})" class="rounded-lg px-3 py-2 text-xs font-semibold text-white" style="background: var(--status-warning);">Edit hold decision</button>
                        @endif
                    </div>
                    @if ($resolvingHold === $result->id)
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-4">
                            @if ($result->lines->isNotEmpty())
                                <div class="sm:col-span-3 rounded-lg border p-3" style="border-color: var(--border); background: var(--surface-3);">
                                    <div class="text-xs mb-2" style="color: var(--text-secondary);">Resolve each held SKU separately. The two values in every row must equal that SKU's held quantity.</div>
                                    <div class="overflow-x-auto"><table class="w-full min-w-[620px] text-xs"><thead><tr class="text-left" style="color: var(--text-muted);"><th class="py-2 pr-2">SKU</th><th class="py-2 pr-2">Held</th><th class="py-2 pr-2">Move to accepted</th><th class="py-2">Move to rejected</th></tr></thead><tbody>
                                        @foreach ($result->lines->where('qc_hold_qty', '>', 0) as $line)
                                            <tr wire:key="hold-line-{{ $line->id }}" style="border-top: 1px solid var(--border);"><td class="py-2 pr-2 font-semibold" style="color: var(--text-primary);">{{ $line->sku }}</td><td class="py-2 pr-2">{{ $line->qc_hold_qty }}</td><td class="py-2 pr-2"><input wire:model="holdLineDecisions.{{ $line->id }}.accepted" type="number" min="0" class="w-28 rounded-lg border px-2 py-2" style="border-color: var(--border);" /></td><td class="py-2"><input wire:model="holdLineDecisions.{{ $line->id }}.rejected" type="number" min="0" class="w-28 rounded-lg border px-2 py-2" style="border-color: var(--border);" /></td></tr>
                                        @endforeach
                                    </tbody></table></div>
                                </div>
                            @else
                                <label class="flex flex-col gap-1.5 text-sm"><span class="font-medium" style="color: var(--text-primary);">Move to accepted</span><input wire:model="holdAccepted" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" /></label>
                                <label class="flex flex-col gap-1.5 text-sm"><span class="font-medium" style="color: var(--text-primary);">Move to rejected</span><input wire:model="holdRejected" type="number" min="0" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" /></label>
                            @endif
                            <label class="flex flex-col gap-1.5 text-sm sm:col-span-3"><span class="font-medium" style="color: var(--text-primary);">Resolution comment</span><textarea wire:model="holdResolutionNote" rows="2" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="Why was this hold accepted or rejected?"></textarea></label>
                            @error('holdAccepted') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            @error('holdRejected') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            @error('holdLineDecisions') <span class="text-xs sm:col-span-3" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            @error('holdResolutionNote') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                            <div class="sm:col-span-3 flex gap-2"><button type="button" wire:click="resolveHold" class="rounded-lg px-3 py-2 text-sm font-semibold text-white" style="background: var(--brand);">Save decision</button><button type="button" wire:click="cancelResolveHold" class="rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--text-primary);">Cancel</button></div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="text-sm py-3" style="color: var(--status-good);">No unresolved QC holds.</div>
            @endforelse
        </div>
    </section>
</div>
