<?php

use App\Models\Rfq;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

// RFQ (admin/rfq.blade.php) quotes each vendor request one at a time.
// This screen is the missing piece: once two or more vendors have quoted
// the same SKU, line them up side by side and pick a winner in one action,
// instead of manually cross-referencing separate RFQ rows.
new #[Layout('layouts.app')] class extends Component
{
    public function selectWinner(int $id): void
    {
        $winner = Rfq::findOrFail($id);
        if ($winner->status !== 'quoted') {
            return;
        }

        $winner->update(['status' => 'selected']);

        Rfq::where('sku', $winner->sku)
            ->where('id', '!=', $winner->id)
            ->whereIn('status', ['submitted', 'quoted'])
            ->get()
            ->each(function (Rfq $losing) {
                $losing->update(['status' => 'closed', 'admin_notes' => trim(($losing->admin_notes ?? '').' Lost to a lower/preferred quote for this SKU.')]);
            });

        AuditLogger::log('Quote selected', "{$winner->sku} · {$winner->vendor_name} · ₹{$winner->quoted_price}", $winner);
    }

    public function with(): array
    {
        $groups = Rfq::whereIn('status', ['submitted', 'quoted', 'selected'])
            ->orderBy('sku')
            ->orderByRaw("FIELD(status, 'selected', 'quoted', 'submitted')")
            ->orderBy('quoted_price')
            ->get()
            ->groupBy('sku');

        return [
            'groups' => $groups,
            'comparableCount' => $groups->filter(fn ($rows) => $rows->where('status', 'quoted')->count() + $rows->where('status', 'selected')->count() >= 2)->count(),
        ];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Quote Comparison</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Every SKU with an open or quoted RFQ, vendors side by side. {{ $comparableCount }} SKU(s) have more than one quote ready to compare.</p>

    <div class="flex flex-col gap-4">
        @forelse ($groups as $sku => $rows)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">{{ $sku }}</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                                <th class="py-2 pr-2 font-medium">Vendor</th>
                                <th class="py-2 pr-2 font-medium">Quantity</th>
                                <th class="py-2 pr-2 font-medium">Quoted Price</th>
                                <th class="py-2 pr-2 font-medium">Status</th>
                                <th class="py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr style="border-top: 1px solid var(--border);">
                                    <td class="py-2 pr-2" style="color: var(--text-primary);">{{ $row->vendor_name }}</td>
                                    <td class="py-2 pr-2" style="color: var(--text-secondary);">{{ $row->quantity }}</td>
                                    <td class="py-2 pr-2" style="color: var(--text-primary);">{{ $row->quoted_price ? '₹'.number_format($row->quoted_price, 2) : '—' }}</td>
                                    <td class="py-2 pr-2">
                                        <span class="text-xs font-medium capitalize px-2 py-0.5 rounded" style="background: var(--surface-2); color: {{ $row->status === 'selected' ? 'var(--status-good)' : ($row->status === 'quoted' ? 'var(--text-primary)' : 'var(--status-warning)') }};">{{ $row->status }}</span>
                                    </td>
                                    <td class="py-2">
                                        @if ($row->status === 'quoted')
                                            <button wire:click="selectWinner({{ $row->id }})" wire:confirm="Select {{ $row->vendor_name }} for {{ $sku }}? Other open quotes for this SKU will be closed." class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--status-good); color: var(--status-good);">Select winner</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No open RFQs to compare yet.</div>
        @endforelse
    </div>
</div>
