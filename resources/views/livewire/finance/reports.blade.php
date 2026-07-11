<?php

use App\Models\FinanceRecord;
use Illuminate\Support\Facades\Response;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    // Zoho Books/Inventory export isn't wired up yet — this stays a
    // disabled placeholder in the UI until API credentials are available.
    public string $source = 'database';

    public function exportCsv()
    {
        $records = FinanceRecord::with('gateEntry')->orderBy('created_at')->get();

        return Response::streamDownload(function () use ($records) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Gate No', 'Vendor', 'Invoice Number', 'Invoice Value', 'Accepted Value', 'Deduction Defective', 'Deduction Rejected', 'Deduction Missing', 'Final Payable', 'Vendor Status', 'Created At']);

            foreach ($records as $r) {
                fputcsv($out, [
                    $r->gateEntry?->gate_no,
                    $r->vendor_name,
                    $r->invoice_number,
                    $r->invoice_value,
                    $r->accepted_value,
                    $r->deduction_defective,
                    $r->deduction_rejected,
                    $r->deduction_missing,
                    $r->final_payable,
                    $r->vendor_status,
                    $r->created_at->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($out);
        }, 'finance-report-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function with(): array
    {
        $records = FinanceRecord::all();

        return [
            'totalInvoiceValue' => $records->sum('invoice_value'),
            'totalAcceptedValue' => $records->sum('accepted_value'),
            'totalDeductions' => $records->sum(fn ($r) => $r->deduction_defective + $r->deduction_rejected + $r->deduction_missing),
            'totalFinalPayable' => $records->sum('final_payable'),
            'byMonth' => $records->groupBy(fn ($r) => $r->created_at->format('M Y'))->map->count(),
        ];
    }
}; ?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Reports</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Final payable totals across every closed gate entry.</p>

    <div class="grid grid-cols-2 gap-3 mb-6">
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Total Invoice Value</div>
            <div class="text-xl font-semibold mt-1" style="color: var(--text-primary);">₹{{ number_format($totalInvoiceValue, 2) }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Total Accepted Value</div>
            <div class="text-xl font-semibold mt-1" style="color: var(--text-primary);">₹{{ number_format($totalAcceptedValue, 2) }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Total Deductions</div>
            <div class="text-xl font-semibold mt-1" style="color: var(--status-critical);">₹{{ number_format($totalDeductions, 2) }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-xs" style="color: var(--text-muted);">Final Payable</div>
            <div class="text-xl font-semibold mt-1" style="color: var(--status-good);">₹{{ number_format($totalFinalPayable, 2) }}</div>
        </div>
    </div>

    <div class="rounded-lg border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Records by month</h2>
        @foreach ($byMonth as $month => $count)
            <div class="flex justify-between text-sm py-1.5" style="color: var(--text-secondary);">
                <span>{{ $month }}</span>
                <span style="color: var(--text-primary);">{{ $count }}</span>
            </div>
        @endforeach
    </div>

    <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Export</h2>
        <label class="flex flex-col gap-1.5 text-sm mb-3">
            <span class="font-medium" style="color: var(--text-primary);">Data source</span>
            <select wire:model="source" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);">
                <option value="database">Our database</option>
                <option value="zoho">Zoho (coming soon)</option>
            </select>
        </label>
        @if ($source === 'database')
            <button wire:click="exportCsv" class="rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Export CSV</button>
        @else
            <button disabled class="rounded-lg px-3.5 py-2 text-sm font-medium text-white opacity-50 cursor-not-allowed" style="background: var(--brand);">Export from Zoho (not connected yet)</button>
        @endif
    </div>
</div>
