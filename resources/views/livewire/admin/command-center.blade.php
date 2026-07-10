<?php

use App\Models\FinanceRecord;
use App\Models\GateEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        return [
            'stages' => [
                ['label' => 'Pending Validation', 'count' => GateEntry::where('status', 'pending_validation')->count(), 'tone' => 'warning'],
                ['label' => 'Validated', 'count' => GateEntry::where('status', 'validated')->count(), 'tone' => 'good'],
                ['label' => 'Unloading', 'count' => GateEntry::where('status', 'unloading')->count(), 'tone' => 'warning'],
                ['label' => 'Ready for QC', 'count' => GateEntry::where('status', 'grn')->count(), 'tone' => 'warning'],
                ['label' => 'QC Done', 'count' => GateEntry::where('status', 'qc_done')->count(), 'tone' => 'good'],
                ['label' => 'Closed', 'count' => GateEntry::where('status', 'closed')->count(), 'tone' => 'good'],
            ],
            'pendingPayable' => FinanceRecord::where('vendor_status', 'pending')->sum('final_payable'),
        ];
    }
}; ?>

<div class="max-w-5xl mx-auto">
    <div class="mb-5">
        <h1 class="text-xl sm:text-2xl font-semibold" style="color: var(--text-primary);">Command Center</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Every gate entry's position across the full Inward → GRN pipeline.</p>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
        @foreach ($stages as $s)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-xs" style="color: var(--text-muted);">{{ $s['label'] }}</div>
                <div class="text-2xl font-semibold mt-1" style="color: {{ $s['tone'] === 'good' ? 'var(--status-good)' : 'var(--status-warning)' }};">{{ $s['count'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs" style="color: var(--text-muted);">Pending Vendor Payable (all vendors)</div>
        <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">₹{{ number_format($pendingPayable, 2) }}</div>
    </div>
</div>
