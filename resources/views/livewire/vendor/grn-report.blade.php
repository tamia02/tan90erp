<?php

use App\Models\GateEntry;
use App\Models\ValidationIssue;
use App\Services\AuditLogger;
use App\Support\VendorIdentity;
use App\Support\ReportColumns;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public int $gateId;
    public string $responseNote = '';
    public $cnDnDocument = null;
    public array $reportColumns = ['sku', 'po_qty', 'physical_received', 'accepted_qty', 'rejected_qty', 'defective_qty', 'qc_hold_qty', 'missing_qty', 'unit_price', 'invoice_amount', 'accepted_value'];

    public function updatedReportColumns(): void
    {
        $this->reportColumns = array_values(array_intersect(array_keys(ReportColumns::GRN), $this->reportColumns));
        if (! $this->reportColumns) $this->reportColumns = array_keys(ReportColumns::GRN);
    }

    public function mount(GateEntry $gate): void
    {
        abort_unless(VendorIdentity::names(auth()->user())->contains($gate->vendor_name) || VendorIdentity::isShared(auth()->user()), 403);
        abort_unless($gate->grnRecord?->posted && (int) ($gate->grnRecord?->qc_hold_qty ?? 0) === 0, 404);
        $this->gateId = $gate->id;
    }

    public function respond(string $action): void
    {
        $gate = GateEntry::with(['grnRecord.lines', 'qcResult.lines'])->findOrFail($this->gateId);
        abort_unless(VendorIdentity::names(auth()->user())->contains($gate->vendor_name) || VendorIdentity::isShared(auth()->user()), 403);
        $grn = $gate->grnRecord;
        abort_unless($grn?->posted && (int) ($grn->qc_hold_qty ?? 0) === 0, 404);

        if ($grn->vendor_response) {
            session()->flash('success', 'This GRN already has a vendor response.');

            return;
        }

        if (! in_array($action, ['accepted', 'escalated'], true)) {
            return;
        }

        $unaccepted = $grn->lines->isNotEmpty()
            ? (int) $grn->lines->sum(fn ($line) => (int) $line->rejected_qty + (int) $line->defective_qty + (int) $line->missing_qty)
            : (int) $grn->rejected_qty + (int) $grn->defective_qty + (int) $grn->missing_qty;
        $rules = ['responseNote' => [$action === 'escalated' ? 'required' : 'nullable', 'string', 'max:2000']];
        if ($action === 'accepted' && $unaccepted > 0) {
            $rules['cnDnDocument'] = ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'];
        } else {
            $rules['cnDnDocument'] = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'];
        }
        $this->validate($rules, ['cnDnDocument.required' => 'Upload the CN/DN for the unaccepted quantity before accepting this GRN.']);

        $documentPath = $this->cnDnDocument?->store('vendor-cn-dn', 'local');
        $alreadyResponded = false;

        // A response belongs to this exact gate-linked GRN, never to the PO
        // as a whole. Lock the row so duplicate clicks/open tabs cannot
        // complete or overwrite a sibling GRN.
        DB::transaction(function () use ($gate, $action, $documentPath, &$alreadyResponded): void {
            $lockedGrn = $gate->grnRecord()->lockForUpdate()->firstOrFail();

            if ($lockedGrn->vendor_response) {
                $alreadyResponded = true;

                return;
            }

            $lockedGrn->update([
                'vendor_response' => $action,
                'vendor_response_note' => $this->responseNote ?: null,
                'vendor_cn_dn_path' => $documentPath,
                'vendor_responded_at' => now(),
                'vendor_responded_by' => auth()->id(),
            ]);

            if ($action === 'escalated') {
                ValidationIssue::updateOrCreate(
                    ['gate_entry_id' => $gate->id, 'code' => 'VENDOR_GRN_ESCALATION'],
                    [
                        'type' => 'workflow',
                        'sku' => $lockedGrn->sku,
                        'title' => 'Vendor escalated GRN discrepancy',
                        'description' => 'Vendor has escalated the posted GRN for Store Manager review.',
                        'severity' => 'redFlag',
                        'status' => 'open',
                        'owner' => 'Store Manager',
                        'note' => $this->responseNote,
                    ],
                );
            }

            AuditLogger::log($action === 'accepted' ? 'Vendor accepted GRN' : 'Vendor escalated GRN', "{$gate->po_number} · {$gate->gate_no}", $lockedGrn);
        });

        if ($alreadyResponded) {
            session()->flash('success', 'This GRN already has a vendor response.');

            return;
        }

        session()->flash('success', $action === 'accepted' ? 'GRN accepted. The CN/DN is linked to the vendor reconciliation record.' : 'GRN escalation sent to Store Manager for review.');
        $this->reset(['responseNote', 'cnDnDocument']);
    }

    public function with(): array
    {
        $gate = GateEntry::with(['grnRecord.lines', 'qcResult.lines'])->findOrFail($this->gateId);
        abort_unless(VendorIdentity::names(auth()->user())->contains($gate->vendor_name) || VendorIdentity::isShared(auth()->user()), 403);

        $grn = $gate->grnRecord;
        $unacceptedQuantity = $grn?->lines?->isNotEmpty()
            ? (int) $grn->lines->sum(fn ($line) => (int) $line->rejected_qty + (int) $line->defective_qty + (int) $line->missing_qty)
            : (int) ($grn?->rejected_qty ?? 0) + (int) ($grn?->defective_qty ?? 0) + (int) ($grn?->missing_qty ?? 0);
        $defectiveQuantity = $grn?->lines?->isNotEmpty()
            ? (int) $grn->lines->sum('defective_qty')
            : (int) ($grn?->defective_qty ?? $gate->qcResult?->defective_qty ?? 0);
        $rejectedQuantity = $grn?->lines?->isNotEmpty()
            ? (int) $grn->lines->sum('rejected_qty')
            : (int) ($grn?->rejected_qty ?? $gate->qcResult?->rejected_qty ?? 0);

        return [
            'gate' => $gate,
            'grn' => $grn,
            'unacceptedQuantity' => $unacceptedQuantity,
            'defectiveQuantity' => $defectiveQuantity,
            'rejectedQuantity' => $rejectedQuantity,
            'reportColumns' => array_values(array_intersect(array_keys(ReportColumns::GRN), $this->reportColumns)) ?: array_keys(ReportColumns::GRN),
            'columnLabels' => ReportColumns::GRN,
            'reportRows' => ReportColumns::grnRows($gate, $grn),
        ];
    }
}; ?>

<div class="space-y-5">
    @if (session('success'))
        <div role="status" class="rounded-xl border px-4 py-3 text-sm font-medium" style="background: var(--status-good-bg); border-color: var(--status-good); color: var(--status-good);">{{ session('success') }}</div>
    @endif
    <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Vendor Module · PO {{ $gate->po_number ?: '—' }}</div>
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <div><h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">GRN report</h1><p class="text-sm mt-1" style="color: var(--text-secondary);">This report is published automatically after GRN closure. It records accepted, rejected, defective, on-hold and missing quantities; only accepted goods form the payable basis.</p></div>
            <div class="flex flex-wrap gap-2"><a href="{{ route('grn.export.pdf', ['gate' => $gate, 'columns' => implode(',', $reportColumns)]) }}" class="rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Download PDF</a><a href="{{ route('grn.export.excel', ['gate' => $gate, 'columns' => implode(',', $reportColumns)]) }}" class="rounded-lg border px-3 py-2 text-sm font-semibold" style="border-color: var(--border); color: var(--brand);">Download Excel</a></div>
        </div>
    </section>

    <section class="rounded-2xl border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
            <div><div class="text-xs" style="color: var(--text-muted);">Gate</div><strong style="color: var(--text-primary);">{{ $gate->gate_no }}</strong></div>
            <div><div class="text-xs" style="color: var(--text-muted);">Vendor</div><strong style="color: var(--text-primary);">{{ $gate->vendor_name }}</strong></div>
            <div><div class="text-xs" style="color: var(--text-muted);">Invoice</div><strong style="color: var(--text-primary);">{{ $gate->invoice_number ?: '—' }}</strong></div>
            <div><div class="text-xs" style="color: var(--text-muted);">Dock</div><strong style="color: var(--text-primary);">{{ $gate->loading_dock ?: '—' }}</strong></div>
        </div>
        <details class="rounded-lg border p-3 mt-4" style="border-color:var(--border);"><summary class="cursor-pointer text-sm font-semibold">Customize report columns (exports follow this selection)</summary><div class="flex flex-wrap gap-x-4 gap-y-2 mt-3">@foreach($columnLabels as $column => $label)<label class="inline-flex gap-2 items-center text-xs"><input type="checkbox" wire:model.live="reportColumns" value="{{ $column }}">{{ $label }}</label>@endforeach</div></details>
        <div class="overflow-x-auto mt-3"><table class="w-full min-w-[720px] text-sm" data-listing-ignore><thead><tr class="text-left text-xs" style="color: var(--text-muted);">@foreach($reportColumns as $column)<th class="py-2 pr-3">{{ $columnLabels[$column] }}</th>@endforeach</tr></thead><tbody>
            @forelse ($reportRows as $row)
                <tr style="border-top:1px solid var(--border);">@foreach($reportColumns as $column)<td class="py-3 pr-3 {{ $column === 'sku' ? 'font-semibold' : '' }}" style="color:{{ in_array($column,['accepted_qty'],true) ? 'var(--status-good)' : (in_array($column,['rejected_qty','defective_qty'],true) ? 'var(--status-critical)' : 'var(--text-primary)') }}">{{ in_array($column,['unit_price'],true) && $row[$column] !== null ? '₹'.number_format((float)$row[$column],4) : (in_array($column,['invoice_amount','accepted_value'],true) && $row[$column] !== null ? '₹'.number_format((float)$row[$column],2) : ($row[$column] ?? '—')) }}</td>@endforeach</tr>
            @empty<tr style="border-top:1px solid var(--border);"><td colspan="{{ count($reportColumns) }}" class="py-3">No SKU lines are available.</td></tr>@endforelse
        </tbody></table></div>
        <div class="mt-3 rounded-lg border p-3 text-xs" style="border-color:var(--border);background:var(--surface-2);color:var(--text-secondary);">Total quantity requiring CN/DN: <strong style="color:var(--status-critical);">{{ $unacceptedQuantity }}</strong>. This is calculated from all SKU lines; the vendor does not need to calculate it manually. Hold is already resolved before GRN posting.</div>
    </section>

    <section class="rounded-2xl border p-4" style="background: var(--surface-3); border-color: var(--border);">
        @if ($grn->vendor_response)
            <div class="rounded-lg border p-3 text-sm" style="border-color:var(--status-good);background:var(--status-good-bg);color:var(--status-good);">Vendor response recorded: <strong>{{ ucfirst($grn->vendor_response) }}</strong> on {{ $grn->vendor_responded_at?->format('d M Y, H:i') }}.</div>
            @if ($grn->vendor_response_note)<p class="text-sm mt-3" style="color:var(--text-secondary);">{{ $grn->vendor_response_note }}</p>@endif
        @else
            <h2 class="text-lg font-semibold" style="color:var(--text-primary);">Confirm GRN or escalate</h2>
            <p class="text-sm mt-1" style="color:var(--text-secondary);">Accept the final quantities and attach a credit note/debit note for the calculated unaccepted quantity, or escalate with a clear reason to Store Manager.</p>
            <div class="mt-4 space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" aria-label="Read-only quantities for CN/DN">
                    <div class="rounded-lg border p-3 text-sm" style="border-color:var(--border);background:var(--surface-2);"><div class="text-xs" style="color:var(--text-muted);">Defective Qty</div><strong class="text-base" style="color:var(--status-critical);">{{ $defectiveQuantity }}</strong></div>
                    <div class="rounded-lg border p-3 text-sm" style="border-color:var(--border);background:var(--surface-2);"><div class="text-xs" style="color:var(--text-muted);">Rejected Qty</div><strong class="text-base" style="color:var(--status-critical);">{{ $rejectedQuantity }}</strong></div>
                    <div class="rounded-lg border p-3 text-sm" style="border-color:var(--border);background:var(--surface-2);"><div class="text-xs" style="color:var(--text-muted);">Total quantity requiring CN/DN</div><strong class="text-base" style="color:var(--status-critical);">{{ $unacceptedQuantity }}</strong></div>
                </div>
                <label class="flex flex-col gap-1.5 text-sm"><span class="font-medium" style="color:var(--text-primary);">Credit note / debit note document <span class="font-normal text-xs">({{ $unacceptedQuantity > 0 ? 'required for '.$unacceptedQuantity.' units' : 'not required when total is zero' }})</span></span><input wire:model="cnDnDocument" type="file" accept=".pdf,.jpg,.jpeg,.png" class="rounded-lg border px-3 py-2 text-sm" style="border-color:var(--border);" />@error('cnDnDocument')<span class="text-xs" style="color:var(--status-critical);">{{ $message }}</span>@enderror</label>
                <label class="flex flex-col gap-1.5 text-sm"><span class="font-medium" style="color:var(--text-primary);">CN/DN details or escalation reason</span><textarea wire:model="responseNote" rows="3" class="rounded-lg border px-3 py-2 text-sm" style="border-color:var(--border);" placeholder="Enter document number, adjustment details, or explain the escalation"></textarea>@error('responseNote')<span class="text-xs" style="color:var(--status-critical);">{{ $message }}</span>@enderror</label>
                <div class="flex flex-wrap gap-2"><button type="button" wire:click="respond('accepted')" wire:loading.attr="disabled" wire:target="respond('accepted')" class="rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" style="background:var(--status-good);">Accept GRN &amp; upload CN/DN</button><button type="button" wire:click="respond('escalated')" wire:loading.attr="disabled" wire:target="respond('escalated')" class="rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" style="background:var(--status-critical);">Escalate to Store Manager</button></div>
            </div>
        @endif
    </section>
</div>
