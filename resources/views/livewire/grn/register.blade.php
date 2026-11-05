<?php

use App\Models\GrnRecord;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use \Livewire\WithFileUploads;

    // Vendor bill tagged to a posted GRN (after QC + GRN): number, date, amount, file.
    public ?int $billFor = null;

    public string $billNumber = '';

    public string $billDate = '';

    public string $billAmount = '';

    public $billFile;

    public function openBill(int $id): void
    {
        $grn = GrnRecord::with('gateEntry')->findOrFail($id);
        $this->billFor = $grn->id;
        $this->billNumber = (string) ($grn->vendor_bill_number ?: $grn->gateEntry?->invoice_number);
        $this->billDate = ($grn->vendor_bill_date ?? $grn->gateEntry?->po_bill_date)?->toDateString() ?? '';
        $this->billAmount = (string) ($grn->vendor_bill_amount ?? $grn->gateEntry?->invoice_amount ?? '');
        $this->reset('billFile');
        $this->resetValidation();
    }

    public function saveBill(): void
    {
        $grn = GrnRecord::findOrFail($this->billFor);
        abort_unless($grn->posted, 422, 'Post the GRN first.');
        $this->validate([
            'billNumber' => ['required', 'string', 'max:100'],
            'billDate' => ['required', 'date'],
            'billAmount' => ['nullable', 'numeric', 'min:0'],
            'billFile' => [$grn->vendor_bill_path ? 'nullable' : 'required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [], ['billNumber' => 'vendor bill number', 'billDate' => 'bill date', 'billAmount' => 'bill amount', 'billFile' => 'bill file']);

        $grn->update([
            'vendor_bill_number' => trim($this->billNumber),
            'vendor_bill_date' => $this->billDate,
            'vendor_bill_amount' => $this->billAmount !== '' ? $this->billAmount : null,
            'vendor_bill_path' => $this->billFile ? $this->billFile->store('vendor-bills') : $grn->vendor_bill_path,
            'vendor_bill_tagged_by' => auth()->id(),
            'vendor_bill_tagged_at' => now(),
        ]);
        \App\Services\AuditLogger::log('Vendor bill tagged to GRN', ($grn->gateEntry?->gate_no ?? 'GRN '.$grn->id).' · bill '.$grn->vendor_bill_number, $grn);
        session()->flash('success', 'Vendor bill '.$grn->vendor_bill_number.' attached to '.($grn->gateEntry?->gate_no ?? 'the GRN').'.');
        $this->reset(['billFor', 'billNumber', 'billDate', 'billAmount', 'billFile']);
    }

    public function with(): array
    {
        $rows = GrnRecord::with(['gateEntry', 'lines'])
            ->orderByDesc('created_at')
            ->get();

        return ['records' => $rows];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Manager Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Purchase Receives (GRN Register)</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Every GRN posted so far. After QC and GRN, attach the vendor's bill in the last column.</p>
    </section>
    @if (session('success'))
        <div class="rounded-lg border px-4 py-3 mb-4 text-sm" role="status" style="border-color: var(--status-good); background: var(--status-good-bg); color: var(--status-good);">{{ session('success') }}</div>
    @endif

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-4 py-2.5 font-medium">Gate No</th>
                        <th class="px-4 py-2.5 font-medium">SKU / Material</th>
                        <th class="px-4 py-2.5 font-medium text-right">Unit Price</th>
                        <th class="px-4 py-2.5 font-medium text-right">Invoice Value</th>
                        <th class="px-4 py-2.5 font-medium text-right">Accepted Value</th>
                        <th class="px-4 py-2.5 font-medium">Accepted</th>
                        <th class="px-4 py-2.5 font-medium">Hold</th>
                        <th class="px-4 py-2.5 font-medium">Defective</th>
                        <th class="px-4 py-2.5 font-medium">Rejected</th>
                        <th class="px-4 py-2.5 font-medium">Bin</th>
                        <th class="px-4 py-2.5 font-medium">Posted</th>
                        <th class="px-4 py-2.5 font-medium">Vendor bill</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $r)
                        @php($lines = $r->lines)
                        @php($multiSku = $lines->count() > 1)
                        @php($line = $lines->first())
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-2.5 font-medium">
                                @if ($r->gateEntry)
                                    <a href="{{ route('gate-entries.show', $r->gateEntry) }}" wire:navigate style="color: var(--brand);">{{ $r->gateEntry->gate_no }}</a>
                                    @if (str_starts_with((string) $r->gateEntry->gate_no, 'VGRN-'))<div class="mt-0.5"><span class="text-[11px] font-semibold px-1.5 py-0.5 rounded-full" style="background: var(--status-warning-bg); color: var(--status-warning);" title="Shipped by the vendor directly to the customer">Virtual GRN</span></div>@endif
                                @else
                                    <span style="color: var(--text-primary);">—</span>
                                @endif
                            </td>
                            @if ($multiSku)
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-secondary);">
                                <details>
                                    <summary class="cursor-pointer font-semibold" style="color:var(--brand);">{{ $lines->count() }} SKU lines · view per-SKU values</summary>
                                    <div class="mt-2 overflow-x-auto">
                                        <table class="min-w-[1050px] w-full text-xs"><thead><tr class="text-left" style="color:var(--text-muted);"><th class="p-2">SKU</th><th class="p-2 text-right">Unit Price</th><th class="p-2 text-right">Invoice Value</th><th class="p-2 text-right">Accepted Value</th><th class="p-2">Accepted</th><th class="p-2">Hold</th><th class="p-2">Defective</th><th class="p-2">Rejected</th><th class="p-2">Bin</th></tr></thead><tbody>
                                            @foreach ($lines as $skuLine)
                                                <tr style="border-top:1px solid var(--border);"><td class="p-2 font-medium">{{ $skuLine->sku }}</td><td class="p-2 text-right">{{ $skuLine->unit_price !== null ? number_format((float) $skuLine->unit_price, 4) : '—' }}</td><td class="p-2 text-right">{{ $skuLine->invoice_amount !== null ? number_format((float) $skuLine->invoice_amount, 2) : '—' }}</td><td class="p-2 text-right font-semibold">{{ $skuLine->accepted_value !== null ? number_format((float) $skuLine->accepted_value, 2) : '—' }}</td><td class="p-2">{{ $skuLine->accepted_qty }}</td><td class="p-2">{{ $skuLine->qc_hold_qty }}</td><td class="p-2">{{ $skuLine->defective_qty }}</td><td class="p-2">{{ $skuLine->rejected_qty }}</td><td class="p-2">{{ $skuLine->suggested_bin ?: $r->suggested_bin ?: '—' }}</td></tr>
                                            @endforeach
                                        </tbody></table>
                                    </div>
                                </details>
                            </td>
                            <td class="px-4 py-2.5 text-right">—</td><td class="px-4 py-2.5 text-right">—</td><td class="px-4 py-2.5 text-right">—</td>
                            <td class="px-4 py-2.5 text-xs" colspan="5" style="color:var(--text-muted);">Amounts and quantities remain separated by SKU above; no combined GRN total.</td>
                            @else
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-secondary);">{{ $line?->sku ?? $r->sku }}</td>
                            <td class="px-4 py-2.5 text-right">{{ $line?->unit_price !== null ? number_format((float) $line->unit_price, 4) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right">{{ $line?->invoice_amount !== null ? number_format((float) $line->invoice_amount, 2) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold">{{ $line?->accepted_value !== null ? number_format((float) $line->accepted_value, 2) : '—' }}</td>
                            <td class="px-4 py-2.5">{{ $line?->accepted_qty ?? $r->accepted_qty }}</td>
                            <td class="px-4 py-2.5" style="{{ ($line?->qc_hold_qty ?? $r->qc_hold_qty) > 0 ? 'color: var(--status-warning); font-weight: 600;' : '' }}">{{ $line?->qc_hold_qty ?? $r->qc_hold_qty }}</td>
                            <td class="px-4 py-2.5">{{ $line?->defective_qty ?? $r->defective_qty }}</td>
                            <td class="px-4 py-2.5">{{ $line?->rejected_qty ?? $r->rejected_qty }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ $line?->suggested_bin ?? $r->suggested_bin }}</td>
                            @endif
                            <td class="px-4 py-2.5 text-xs font-medium" style="color: {{ $r->posted && $r->gateEntry?->putaway_completed_at ? 'var(--status-good)' : 'var(--status-warning)' }};">
                                @if (! $r->posted)
                                    GRN pending
                                @elseif ($r->gateEntry?->putaway_completed_at)
                                    Put-away complete · stock reflected
                                @else
                                    GRN posted · put-away pending
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-xs">
                                @if ($r->vendor_bill_number)
                                    <div class="font-semibold" style="color: var(--status-good);">Bill {{ $r->vendor_bill_number }}</div>
                                    <div style="color: var(--text-muted);">{{ $r->vendor_bill_date?->format('d M Y') }}@if ($r->vendor_bill_amount !== null) · ₹{{ number_format((float) $r->vendor_bill_amount, 2) }}@endif</div>
                                    <div class="flex gap-2 mt-0.5">
                                        @if ($r->vendor_bill_path)<a href="{{ route('grn.vendor-bill', $r) }}" target="_blank" class="underline" style="color: var(--brand);">View file</a>@endif
                                        @if ($r->posted)<button type="button" wire:click="openBill({{ $r->id }})" class="underline" style="color: var(--text-muted);">Change</button>@endif
                                    </div>
                                @elseif ($r->posted)
                                    <button type="button" wire:click="openBill({{ $r->id }})" class="rounded-lg border px-2 py-1 font-semibold" style="border-color: var(--border); color: var(--brand);">Attach vendor bill</button>
                                @else
                                    <span style="color: var(--text-muted);">After GRN</span>
                                @endif
                            </td>
                        </tr>
                        @if ($billFor === $r->id)
                            <tr wire:key="bill-form-{{ $r->id }}" style="background: var(--surface-2);">
                                <td colspan="12" class="px-4 py-3">
                                    <div class="text-sm font-semibold mb-2" style="color: var(--text-primary);">Vendor bill for {{ $r->gateEntry?->gate_no }} @if ($r->gateEntry?->po_number)· PO {{ $r->gateEntry->po_number }}@endif</div>
                                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 text-xs">
                                        <label class="flex flex-col gap-1"><span class="font-semibold">Bill number *</span><input wire:model="billNumber" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);">@error('billNumber')<span style="color: var(--status-critical);">{{ $message }}</span>@enderror</label>
                                        <label class="flex flex-col gap-1"><span class="font-semibold">Bill date *</span><input wire:model="billDate" type="date" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);">@error('billDate')<span style="color: var(--status-critical);">{{ $message }}</span>@enderror</label>
                                        <label class="flex flex-col gap-1"><span class="font-semibold">Bill amount (incl. GST)</span><input wire:model="billAmount" type="number" step="0.01" min="0" class="rounded-lg border px-2 py-1.5 text-sm" style="border-color: var(--border);">@error('billAmount')<span style="color: var(--status-critical);">{{ $message }}</span>@enderror</label>
                                        <label class="flex flex-col gap-1"><span class="font-semibold">Bill file (PDF/JPG){{ $r->vendor_bill_path ? '' : ' *' }}</span><input wire:model="billFile" type="file" accept=".pdf,.jpg,.jpeg,.png" class="text-xs"><span wire:loading wire:target="billFile" style="color: var(--text-muted);">Uploading…</span>@error('billFile')<span style="color: var(--status-critical);">{{ $message }}</span>@enderror</label>
                                    </div>
                                    <div class="flex gap-2 mt-3">
                                        <button type="button" wire:click="saveBill" wire:loading.attr="disabled" wire:target="saveBill,billFile" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50" style="background: var(--brand);">Save vendor bill</button>
                                        <button type="button" wire:click="$set('billFor', null)" class="rounded-lg border px-3 py-1.5 text-sm" style="border-color: var(--border);">Cancel</button>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="12" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No GRNs posted yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
