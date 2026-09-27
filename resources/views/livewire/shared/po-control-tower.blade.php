<?php

use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderProgressService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Url(as: 'po', keep: true)]
    public string $poSearch = '';

    public string $searchedPo = '';

    public function mount(): void
    {
        $this->searchedPo = trim($this->poSearch);
    }

    public function search(): void
    {
        $this->searchedPo = trim($this->poSearch);
    }

    public function clearSearch(): void
    {
        $this->poSearch = '';
        $this->searchedPo = '';
    }

    public function with(PurchaseOrderProgressService $progress): array
    {
        $query = PurchaseOrder::with('lines')->orderByDesc('updated_at');
        if ($this->searchedPo !== '') {
            $query->where('po_number', $this->searchedPo);
        }

        $orders = $query->limit($this->searchedPo !== '' ? 1 : 20)->get();

        return [
            'orders' => $orders,
            'progress' => $progress->summarize($orders),
        ];
    }
}; ?>

<div class="space-y-5">
    <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Connected PO Control Tower</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">PO number search</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">One view across PO, vendor bills, gate, unloading, QC, GRN, putaway and finance.</p>
        <form wire:submit="search" class="flex flex-col sm:flex-row gap-2 mt-4">
            <input wire:model="poSearch" class="flex-1 rounded-xl border px-3 py-2.5 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);" placeholder="Enter PO number, e.g. POPM26270078" />
            <button class="rounded-xl px-4 py-2.5 text-sm font-semibold text-white" style="background: var(--brand);">Fetch PO details</button>
            @if ($searchedPo)
                <button type="button" wire:click="clearSearch" class="rounded-xl px-4 py-2.5 text-sm font-semibold border" style="border-color: var(--border); color: var(--text-secondary);">Show recent</button>
            @endif
        </form>
    </section>

    @forelse ($orders as $po)
        @php($p = $progress->get($po->po_number))
        <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <div class="text-lg font-semibold" style="color: var(--text-primary);">{{ $po->po_number }}</div>
                    <div class="text-sm" style="color: var(--text-secondary);">{{ $po->vendor_name }} · {{ $po->subject ?? 'Purchase Order' }}</div>
                    <div class="text-xs mt-1" style="color: var(--text-muted);">PO date {{ $po->po_date?->format('d M Y') ?? '—' }} · Due {{ $po->due_date?->format('d M Y') ?? '—' }} · {{ $po->status }}</div>
                </div>
                <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background: {{ $p['stage'] === 'Closed' ? 'var(--status-good-bg)' : 'var(--status-warning-bg)' }}; color: {{ $p['stage'] === 'Closed' ? 'var(--status-good)' : 'var(--status-warning)' }};">{{ $p['stage'] }}</span>
            </div>

            @if ($p['gate_overage_qty'] > 0 || ! empty($p['repeated_invoices']) || ($p['invoice_amount_review_required'] ?? false))
                <aside role="alert" class="rounded-xl border p-3 mt-4" style="border-color: var(--status-warning); background: var(--status-warning-bg); color: var(--status-warning);">
                    <div class="text-sm font-semibold">Review billing and inward records for this PO</div>
                    @if ($p['invoice_amount_review_required'] ?? false)
                        <p class="text-xs mt-1">All ordered units are billed, but the split invoices total ₹{{ number_format($p['invoiced_amount'], 2) }} against the PO total ₹{{ number_format($p['po_total_amount'], 2) }} — ₹{{ number_format(abs($p['invoice_amount_variance']), 2) }} {{ $p['invoice_amount_variance'] > 0 ? 'over' : 'under' }}. Verify source invoices and rounding before Finance review; no invoice or payable has been changed.</p>
                    @endif
                    @if ($p['gate_overage_qty'] > 0)
                        <p class="text-xs mt-1">Recorded inward gate quantity is {{ number_format($p['gate_qty'], 0) }} against {{ number_format($p['ordered_qty'], 0) }} ordered — {{ number_format($p['gate_overage_qty'], 0) }} above the PO quantity.</p>
                    @endif
                    @foreach ($p['repeated_invoices'] as $repeatedInvoice)
                        <p class="text-xs mt-1">Invoice {{ $repeatedInvoice['invoice_number'] }} appears on {{ $repeatedInvoice['gate_count'] }} inward gate records{{ $repeatedInvoice['pending_count'] ? ' ('.$repeatedInvoice['pending_count'].' pending manager review)' : '' }}. Compare the linked gate entries before continuing.</p>
                    @endforeach
                    <p class="text-xs mt-1">These are review cues only; no gate entries or quantities have been changed.</p>
                    @if ($p['pending_validation_count'] > 0)
                        <a href="{{ route('store-manager.entry-approvals') }}" wire:navigate class="inline-flex min-h-10 items-center mt-2 rounded-lg border px-3 py-2 text-xs font-semibold" style="border-color: currentColor; color: var(--status-warning);">Review {{ $p['pending_validation_count'] }} pending inward {{ \Illuminate\Support\Str::plural('entry', $p['pending_validation_count']) }} →</a>
                    @endif
                </aside>
            @endif

            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-4 text-xs">
                <div><div style="color: var(--text-muted);">Ordered</div><strong style="color: var(--text-primary);">{{ number_format($p['ordered_qty'], 0) }}</strong></div>
                <div><div style="color: var(--text-muted);">Billed</div><strong style="color: var(--text-primary);">{{ number_format($p['billed_qty'], 0) }} · {{ $p['invoice_count'] }} bill(s)</strong></div>
                <div><div style="color: var(--text-muted);">Gate quantity</div><strong style="color: var(--text-primary);">{{ number_format($p['gate_qty'], 0) }}</strong></div>
                <div><div style="color: var(--text-muted);">GRN accepted</div><strong style="color: var(--status-good);">{{ number_format($p['grn_qty'], 0) }}</strong></div>
                <div><div style="color: var(--text-muted);">Remaining</div><strong style="color: var(--text-primary);">{{ number_format($p['remaining_qty'], 0) }}</strong></div>
            </div>

            <div class="mt-4 rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2);">
                <div class="flex items-center justify-between gap-2 text-xs">
                    <span style="color: var(--text-secondary);">Billing {{ $p['billed_pct'] }}% · receipt {{ $p['received_pct'] }}%</span>
                    <span class="font-semibold" style="color: var(--brand);">Next: {{ $p['next_action']['label'] }} ({{ $p['next_action']['owner'] }})</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-2 text-xs" style="color: var(--text-secondary);">
                    <div>Vendor acknowledgement: {{ $p['ack']?->accepted ? 'Accepted' : ($p['ack'] ? 'Declined' : 'Pending') }}</div>
                    <div>Invoice value: ₹{{ number_format($p['invoiced_amount'], 2) }}</div>
                    <div>Finance payable: ₹{{ number_format($p['payable'], 2) }}</div>
                </div>
            </div>

            @if ($p['submissions']->isNotEmpty())
                <h2 class="text-sm font-semibold mt-5 mb-2" style="color: var(--text-primary);">Bills / vendor submissions</h2>
                <div class="overflow-x-auto rounded-xl border" style="border-color: var(--border);">
                    <table class="w-full text-xs">
                        <thead><tr style="color: var(--text-muted); background: var(--surface-2);"><th class="px-3 py-2 text-left">Invoice</th><th class="px-3 py-2 text-left">Date</th><th class="px-3 py-2 text-left">Qty</th><th class="px-3 py-2 text-left">Amount</th><th class="px-3 py-2 text-left">Dispatch</th></tr></thead>
                        <tbody>
                            @foreach ($p['submissions'] as $submission)
                                <tr style="border-top: 1px solid var(--border);">
                                    <td class="px-3 py-2 font-medium" style="color: var(--text-primary);">{{ $submission->invoice_number ?: '—' }}</td>
                                    <td class="px-3 py-2" style="color: var(--text-secondary);">{{ $submission->invoice_date?->format('d M Y') ?? '—' }}</td>
                                    <td class="px-3 py-2" style="color: var(--text-secondary);">{{ number_format($submission->invoice_qty ?? 0, 0) }}</td>
                                    <td class="px-3 py-2" style="color: var(--text-secondary);">₹{{ number_format($submission->invoice_amount ?? 0, 2) }}</td>
                                    <td class="px-3 py-2" style="color: var(--text-secondary);">{{ $submission->dispatched_at?->format('d M, H:i') ?? 'Pending' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <h2 class="text-sm font-semibold mt-5 mb-2" style="color: var(--text-primary);">Operational records</h2>
            <div class="space-y-2">
                @forelse ($p['gates'] as $gate)
                    <a href="{{ route('gate-entries.show', $gate) }}" wire:navigate class="block rounded-xl border p-3 hover:opacity-80" style="border-color: var(--border); background: var(--surface-2);">
                        <div class="flex items-center justify-between gap-2"><span class="text-sm font-medium" style="color: var(--text-primary);">{{ $gate->gate_no }} · {{ $gate->invoice_number ?: 'No invoice' }}</span><span class="text-xs" style="color: var(--text-secondary);">{{ \App\Support\GateStatusLabels::label($gate->status) }}</span></div>
                        <div class="grid grid-cols-2 md:grid-cols-5 gap-2 mt-2 text-xs" style="color: var(--text-secondary);"><span>Qty {{ $gate->invoice_qty ?? '—' }}</span><span>QC {{ $gate->qcResult ? 'Done' : 'Pending' }}</span><span>GRN {{ $gate->grnRecord?->posted ? 'Posted' : 'Pending' }}</span><span>Putaway {{ $gate->putaway_completed_at ? 'Done' : 'Pending' }}</span><span>Finance {{ $gate->financeRecord?->vendor_status ?? 'Pending' }}</span></div>
                    </a>
                @empty
                    <div class="rounded-xl border p-3 text-xs" style="border-color: var(--border); color: var(--text-muted);">No gate entry yet. The next action is to create one against the vendor invoice.</div>
                @endforelse
            </div>
        </section>
    @empty
        <div class="rounded-2xl border p-8 text-center text-sm" style="background: var(--surface-3); border-color: var(--border); color: var(--text-muted);">No PO found for “{{ $searchedPo }}”. Check the exact PO number in PO Master.</div>
    @endforelse
</div>
