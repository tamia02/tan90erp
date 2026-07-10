<?php

use App\Models\AuditLogEntry;
use App\Models\FinanceRecord;
use App\Models\GateEntry;
use App\Models\VendorSubmission;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public VendorSubmission $submission;

    public function mount(VendorSubmission $submission): void
    {
        abort_unless($submission->vendor_name === auth()->user()->name, 403);

        $this->submission = $submission;
    }

    public function with(): array
    {
        $gateEntries = GateEntry::where('po_number', $this->submission->po_number)
            ->where('vendor_name', $this->submission->vendor_name)
            ->with(['unloadingRecord', 'qcResult', 'grnRecord', 'financeRecord'])
            ->orderByDesc('created_at')
            ->get();

        $financeRecords = FinanceRecord::whereIn('gate_entry_id', $gateEntries->pluck('id'))->get();

        $auditRows = AuditLogEntry::where('detail', 'like', '%'.$this->submission->po_number.'%')
            ->orWhere('detail', 'like', '%'.$this->submission->invoice_number.'%')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return [
            'gateEntries' => $gateEntries,
            'financeRecords' => $financeRecords,
            'auditRows' => $auditRows,
        ];
    }
}; ?>

<div class="max-w-4xl mx-auto space-y-5">
    <div>
        <a href="{{ route('vendor.submissions') }}" wire:navigate class="text-sm" style="color: var(--text-secondary);">&larr; Back to My Submissions</a>
        <h1 class="text-xl font-semibold mt-1" style="color: var(--text-primary);">Activity — {{ $submission->po_number }}</h1>
        <p class="text-sm" style="color: var(--text-secondary);">Full history for this submission, from your upload through to finance closure.</p>
    </div>

    <section class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Submission</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
            <div>
                <div class="text-xs" style="color: var(--text-muted);">Invoice #</div>
                <div style="color: var(--text-primary);">{{ $submission->invoice_number ?? '-' }}</div>
            </div>
            <div>
                <div class="text-xs" style="color: var(--text-muted);">Material</div>
                <div style="color: var(--text-primary);">{{ $submission->material ?? '-' }}</div>
            </div>
            <div>
                <div class="text-xs" style="color: var(--text-muted);">Qty</div>
                <div style="color: var(--text-primary);">{{ $submission->invoice_qty ?? '-' }}</div>
            </div>
            <div>
                <div class="text-xs" style="color: var(--text-muted);">Status</div>
                <div style="color: {{ $submission->status == 'submitted' || $submission->status == 'acknowledged' ? 'var(--status-good)' : 'var(--status-critical)' }};">{{ ucfirst(str_replace('_', ' ', $submission->status)) }}</div>
            </div>
        </div>
        <div class="flex gap-1.5 text-xs mt-3">
            @if($submission->has_invoice)<span class="px-1.5 py-0.5 rounded bg-blue-100 text-blue-800">Invoice</span>@endif
            @if($submission->has_eway_bill)<span class="px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-800">E-way Bill</span>@endif
            @if($submission->has_lr_pod)<span class="px-1.5 py-0.5 rounded bg-gray-100 text-gray-800">LR/POD</span>@endif
        </div>
        <div class="text-xs mt-3" style="color: var(--text-muted);">Submitted {{ $submission->created_at->format('d M, Y H:i') }}</div>
    </section>

    <section class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Gate &amp; Warehouse Progress</h2>
        <div class="space-y-3">
            @forelse ($gateEntries as $gate)
                <div class="rounded-lg border p-3" style="border-color: var(--border); background: var(--surface-2);">
                    <div class="flex items-center justify-between">
                        <div class="font-medium text-sm" style="color: var(--text-primary);">{{ $gate->gate_no }}</div>
                        <span class="text-xs capitalize" style="color: var(--text-muted);">{{ str_replace('_', ' ', $gate->status) }}</span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-2 text-xs" style="color: var(--text-secondary);">
                        <div>Gate entry: {{ $gate->created_at->format('d M, H:i') }}</div>
                        <div>Unloading: {{ $gate->unloadingRecord?->completed_at?->format('d M, H:i') ?? 'Pending' }}</div>
                        <div>QC: {{ $gate->qcResult ? 'Done' : 'Pending' }}</div>
                        <div>GRN: {{ $gate->grnRecord?->posted ? 'Posted' : 'Pending' }}</div>
                    </div>
                </div>
            @empty
                <p class="text-sm py-4 text-center" style="color: var(--text-muted);">No gate entry recorded yet for this PO.</p>
            @endforelse
        </div>
    </section>

    <section class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Financial Status</h2>
        <div class="space-y-2">
            @forelse ($financeRecords as $fr)
                <div class="rounded-lg border p-3 flex items-center justify-between text-sm" style="border-color: var(--border); background: var(--surface-2);">
                    <div>
                        <div style="color: var(--text-primary);">Invoice value: {{ number_format($fr->invoice_value, 2) }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">Final payable: {{ number_format($fr->final_payable, 2) }}</div>
                    </div>
                    <span class="text-xs font-semibold capitalize" style="color: {{ $fr->vendor_status === 'cleared' ? 'var(--status-good)' : 'var(--status-warning)' }};">{{ $fr->vendor_status }}</span>
                </div>
            @empty
                <p class="text-sm py-4 text-center" style="color: var(--text-muted);">No finance record yet for this PO.</p>
            @endforelse
        </div>
    </section>

    <section class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Audit Trail</h2>
        <div class="flex flex-col divide-y" style="border-color: var(--border);">
            @forelse ($auditRows as $row)
                <div class="py-2.5 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ $row->action }}</div>
                        @if ($row->detail)
                            <div class="text-xs mt-0.5 truncate" style="color: var(--text-muted);">{{ $row->detail }}</div>
                        @endif
                    </div>
                    <span class="text-xs shrink-0" style="color: var(--text-muted);">{{ $row->created_at->format('d M, H:i') }}</span>
                </div>
            @empty
                <p class="text-sm py-4 text-center" style="color: var(--text-muted);">No audit trail rows found for this PO.</p>
            @endforelse
        </div>
    </section>
</div>
