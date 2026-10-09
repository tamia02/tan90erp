<?php

use App\Models\GateEntry;
use App\Models\GrnRecord;
use App\Models\PurchaseOrder;
use App\Services\AuditLogger;
use App\Services\GrnPostingService;
use App\Support\ReportColumns;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $posting = null;
    public string $suggestedBin = '';
    public array $reportColumns = ['sku', 'po_qty', 'physical_received', 'accepted_qty', 'rejected_qty', 'defective_qty', 'qc_hold_qty', 'missing_qty', 'unit_price', 'invoice_amount', 'accepted_value', 'suggested_bin'];

    public function updatedReportColumns(): void
    {
        $this->reportColumns = array_values(array_intersect(array_keys(ReportColumns::GRN), $this->reportColumns));
        if (! $this->reportColumns) $this->reportColumns = array_keys(ReportColumns::GRN);
    }

    public function sendToVendor(int $grnId): void
    {
        $grn = GrnRecord::with('gateEntry')->findOrFail($grnId);
        abort_unless($grn->posted, 404);

        if ((int) ($grn->qc_hold_qty ?? 0) > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'vendorNotification' => 'Resolve the QC hold before sending the GRN report to the vendor.',
            ]);
        }

        if (! $grn->vendor_notified_at) {
            $grn->update(['vendor_notified_at' => now(), 'vendor_notified_by' => auth()->id()]);
            AuditLogger::log('GRN report sent to vendor', "{$grn->gateEntry?->po_number} · {$grn->gateEntry?->gate_no}", $grn);
            session()->flash('success', "GRN report for {$grn->gateEntry?->po_number} is now marked as sent to the vendor.");
        } else {
            session()->flash('success', 'This GRN report was already marked as sent to the vendor.');
        }
    }

    public function openPost(int $gateId): void
    {
        $this->posting = $gateId;
        $this->suggestedBin = (string) (GateEntry::findOrFail($gateId)->final_bin ?? '');
    }

    public function post(): void
    {
        $this->validate(['suggestedBin' => ['required', 'string']]);

        $gate = GateEntry::findOrFail($this->posting);
        $grn = app(GrnPostingService::class)->post($gate, $this->suggestedBin);

        if ($grn) {
            session()->flash('success', $gate->putaway_completed_at
                ? "GRN posted for {$gate->gate_no}; stock is updated in the already-confirmed bin and the entry is closed."
                : "GRN posted for {$gate->gate_no}; stock is updated and ready for Store Executive putaway.");
        } else {
            session()->flash('error', "GRN was not posted for {$gate->gate_no}; complete QC before posting.");
        }

        $this->reset(['posting', 'suggestedBin']);
    }

    public function with(): array
    {
        $queue = GateEntry::with('qcResult.lines')->where('status', 'qc_done')->orderBy('created_at')->get();
        $recentlyPosted = GrnRecord::with(['gateEntry', 'lines'])->where('posted', true)->latest('updated_at')->limit(12)->get();

        $purchaseOrders = PurchaseOrder::whereIn('po_number', $queue->pluck('po_number')->filter())
            ->with('lines')
            ->get()
            ->keyBy('po_number');

        return [
            'queue' => $queue,
            'purchaseOrders' => $purchaseOrders,
            'recentlyPosted' => $recentlyPosted,
            'reportColumns' => array_values(array_intersect(array_keys(ReportColumns::GRN), $this->reportColumns)) ?: array_keys(ReportColumns::GRN),
            'columnLabels' => ReportColumns::GRN,
        ];
    }
}; ?>

<div>
    @if (session('success'))
        <div role="status" class="rounded-xl border px-4 py-3 mb-4 text-sm font-medium" style="background: var(--status-good-bg); border-color: var(--status-good); color: var(--status-good);">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div role="alert" class="rounded-xl border px-4 py-3 mb-4 text-sm font-medium" style="background: var(--status-critical-bg); border-color: var(--status-critical); color: var(--status-critical);">{{ session('error') }}</div>
    @endif
    @error('grn')
        <div role="alert" class="rounded-xl border px-4 py-3 mb-4 text-sm font-medium" style="background: var(--status-warning-bg); border-color: var(--status-warning); color: var(--status-warning);">{{ $message }}</div>
    @enderror
    @error('vendorNotification')
        <div role="alert" class="rounded-xl border px-4 py-3 mb-4 text-sm font-medium" style="background: var(--status-warning-bg); border-color: var(--status-warning); color: var(--status-warning);">{{ $message }}</div>
    @enderror
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Manager Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Purchase Receive (GRN)</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Post the QC-checked split to stock and send the receipt to Store Executive Putaway for final bin confirmation.</p>
    </section>

    <div class="flex flex-col gap-3">
        @forelse ($queue as $g)
            @php
                $qc = $g->qcResult;
                $po = $purchaseOrders->get($g->po_number);
                $poLine = $po?->primaryLine();
            @endphp
            <div wire:key="grn-check-gate-{{ $g->id }}" class="relative rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3">
                    <a href="{{ route('gate-entries.show', $g) }}" wire:navigate aria-label="Open full GRN report for {{ $g->po_number ?: $g->gate_no }}" class="after:absolute after:inset-0 after:z-0 after:content-[''] after:rounded-lg hover:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style="color:var(--text-primary);">
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $g->gate_no }} · {{ $qc?->lines?->count() > 1 ? $qc->lines->count().' separate SKU lines' : $qc?->sku }}</div>
                        @if (! ($qc?->lines?->count() > 1))
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">Accepted {{ $qc?->accepted_qty }} · Hold {{ $qc?->qc_hold_qty }} · Defective {{ $qc?->defective_qty }} · Rejected {{ $qc?->rejected_qty }}</div>
                        @endif
                        @if ($qc?->lines?->count() > 1)
                            <div class="mt-3 rounded-lg border px-3 py-2 text-xs" style="border-color:var(--border);background:var(--surface-2);color:var(--text-secondary);">Multi-SKU receipt: each SKU amount is shown on its own row below. No combined GRN amount is displayed.</div>
                            <div class="overflow-x-auto mt-3"><table class="min-w-[900px] text-xs"><thead><tr class="text-left" style="color:var(--text-muted);"><th class="pr-3 py-1">SKU</th><th class="pr-3 py-1">PO qty</th><th class="pr-3 py-1">Received</th><th class="pr-3 py-1">Accepted</th><th class="pr-3 py-1">Hold</th><th class="pr-3 py-1">Defective</th><th class="pr-3 py-1">Rejected</th><th class="pr-3 py-1">Unit price</th><th class="pr-3 py-1">Accepted value</th></tr></thead><tbody>
                                @foreach ($qc->lines as $line)<tr style="border-top:1px solid var(--border);"><td class="pr-3 py-1.5 font-semibold" style="color:var(--text-primary);">{{ $line->sku }}</td><td class="pr-3 py-1.5">{{ $line->po_qty }}</td><td class="pr-3 py-1.5">{{ $line->physical_received }}</td><td class="pr-3 py-1.5" style="color:var(--status-good);">{{ $line->accepted_qty }}</td><td class="pr-3 py-1.5" style="color:var(--status-warning);">{{ $line->qc_hold_qty }}</td><td class="pr-3 py-1.5">{{ $line->defective_qty }}</td><td class="pr-3 py-1.5">{{ $line->rejected_qty }}</td><td class="pr-3 py-1.5">₹{{ number_format((float) $line->unit_price, 4) }}</td><td class="pr-3 py-1.5 font-semibold">₹{{ number_format((int) $line->accepted_qty * (float) $line->unit_price, 2) }}</td></tr>@endforeach
                            </tbody></table></div>
                        @endif
                        @if ($qc?->qc_reasons)
                            <div class="text-xs mt-1" style="color: var(--text-secondary);">QC notes: {{ $qc->qc_reasons }}</div>
                        @endif
                    </a>
                    @if (($qc?->qc_hold_qty ?? 0) > 0)
                        <div class="relative z-10 flex items-center gap-2 flex-wrap justify-end">
                            <span class="rounded-lg px-3 py-1.5 text-xs font-semibold" style="background: var(--status-warning-bg); color: var(--status-warning);">GRN pending · QC hold {{ $qc->qc_hold_qty }} must be resolved</span>
                            <a href="{{ route('unloading.putaway', ['gate' => $g->id]) }}#putaway-gate-{{ $g->id }}" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-white" style="background: var(--brand);">Proceed to Putaway</a>
                        </div>
                    @elseif ($posting !== $g->id)
                        <button type="button" wire:click="openPost({{ $g->id }})" wire:loading.attr="disabled" wire:target="openPost({{ $g->id }})" class="relative z-10 rounded-lg px-3 py-1.5 text-sm font-medium text-white shrink-0 disabled:opacity-50" style="background: var(--brand);">Post GRN</button>
                    @endif
                </div>

                @if ($poLine && ! ($qc?->lines?->count() > 1))
                    <div class="mt-3 rounded-lg border p-3 text-xs" style="border-color: var(--border); background: var(--surface-2);">
                        <div class="font-semibold mb-1.5" style="color: var(--text-muted);">QC quantity vs PO price ({{ $g->po_number }})</div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <div><span style="color: var(--text-muted);">PO qty</span> · <span style="color: var(--text-primary);">{{ $poLine->quantity }}</span></div>
                            <div><span style="color: var(--text-muted);">List price</span> · <span style="color: var(--text-primary);">₹{{ number_format($poLine->list_price, 2) }}</span></div>
                            <div><span style="color: var(--text-muted);">Accepted value</span> · <span style="color: var(--status-good);">₹{{ number_format(($qc?->accepted_qty ?? 0) * $poLine->list_price, 2) }}</span></div>
                            <div><span style="color: var(--text-muted);">Qty variance</span> · <span style="color: {{ ($qc?->accepted_qty ?? 0) < $poLine->quantity ? 'var(--status-warning)' : 'var(--status-good)' }};">{{ ($qc?->accepted_qty ?? 0) - $poLine->quantity }}</span></div>
                        </div>
                    </div>
                @endif

                @if ($posting === $g->id)
                    {{-- relative z-10: sit above the card's stretched gate-entry link, or clicks on the bin field open the entry instead --}}
                    <div class="relative z-10 mt-4">
                        <label class="flex flex-col gap-1.5 text-sm">
                            <span class="font-medium" style="color: var(--text-primary);">Suggested bin</span>
                            <input wire:model="suggestedBin" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" placeholder="e.g. BHW-PCM-A1" />
                            @error('suggestedBin') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                        </label>
                        <button wire:click="post" wire:loading.attr="disabled" wire:target="post" class="mt-4 rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50" style="background: var(--brand);">Post GRN &amp; update stock</button>
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">Nothing waiting for GRN posting.</div>
        @endforelse
    </div>

    <section class="rounded-2xl border p-4 mt-8" style="background: var(--surface-3); border-color: var(--border);">
        <div class="mb-3">
            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Recently posted GRNs</h2>
            <p class="text-xs mt-1" style="color: var(--text-secondary);">Posted GRNs are published to the vendor automatically after QC hold reaches zero. This list also lets you backfill notification for older records.</p>
        </div>
        <details class="rounded-lg border p-3 mb-3" style="border-color:var(--border);"><summary class="cursor-pointer text-sm font-semibold">Add report columns (PDF and Excel exports use this selection)</summary><div class="flex flex-wrap gap-x-4 gap-y-2 mt-3">@foreach($columnLabels as $column => $label)<label class="inline-flex gap-2 items-center text-xs"><input type="checkbox" wire:model.live="reportColumns" value="{{ $column }}">{{ $label }}</label>@endforeach</div></details>
        <div class="flex flex-col gap-2">
            @forelse ($recentlyPosted as $record)
                <div wire:key="grn-posted-record-{{ $record->id }}" class="relative rounded-xl border p-3 flex items-center justify-between gap-3 flex-wrap hover:border-indigo-400 focus-within:ring-2" style="border-color: var(--border); background: var(--surface-2);">
                    <a href="{{ route('gate-entries.show', $record->gateEntry) }}" wire:navigate aria-label="Open full GRN report for {{ $record->gateEntry?->po_number ?: $record->gateEntry?->gate_no }}" class="after:absolute after:inset-0 after:z-0 after:content-[''] after:rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2">
                        <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $record->gateEntry?->po_number ?: $record->gateEntry?->gate_no }}</div>
                        <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $record->gateEntry?->vendor_name }} · {{ $record->lines->count() > 1 ? $record->lines->count().' SKUs' : '1 SKU' }} · Accepted {{ $record->accepted_qty }} · Rejected {{ $record->rejected_qty }} · Defective {{ $record->defective_qty }} · Hold {{ $record->qc_hold_qty }}</div>
                        <span class="text-xs font-semibold" style="color:var(--brand);">Open full GRN report →</span>
                    </a>
                    <div class="relative z-10 flex items-center gap-2">
                        <a href="{{ route('grn.export.pdf', ['gate' => $record->gateEntry, 'columns' => implode(',', $reportColumns)]) }}" class="text-xs font-semibold" style="color: var(--brand);">Download PDF</a>
                        <a href="{{ route('grn.export.excel', ['gate' => $record->gateEntry, 'columns' => implode(',', $reportColumns)]) }}" class="text-xs font-semibold" style="color: var(--brand);">Download Excel</a>
                        @if ((int) $record->qc_hold_qty > 0)
                            <span class="text-xs font-semibold" style="color: var(--status-warning);">Resolve hold before send</span>
                        @elseif ($record->vendor_notified_at)
                            <span class="text-xs font-semibold" style="color: var(--status-good);">Sent {{ $record->vendor_notified_at->format('d M, H:i') }}</span>
                        @else
                            <button type="button" wire:click="sendToVendor({{ $record->id }})" wire:loading.attr="disabled" wire:target="sendToVendor({{ $record->id }})" class="rounded-lg px-3 py-2 text-xs font-semibold text-white disabled:opacity-50" style="background: var(--brand);">Send to Vendor</button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="text-sm py-3" style="color: var(--text-muted);">No posted GRNs yet.</div>
            @endforelse
        </div>
    </section>
</div>
