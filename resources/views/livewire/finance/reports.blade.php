<?php

use App\Models\FinanceRecord;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
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

    <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <h2 class="font-semibold text-sm mb-3" style="color: var(--text-primary);">Records by month</h2>
        @foreach ($byMonth as $month => $count)
            <div class="flex justify-between text-sm py-1.5" style="color: var(--text-secondary);">
                <span>{{ $month }}</span>
                <span style="color: var(--text-primary);">{{ $count }}</span>
            </div>
        @endforeach
    </div>
</div>
