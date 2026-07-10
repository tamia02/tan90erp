<?php

use App\Models\LedgerEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        $entries = LedgerEntry::all();

        $balance = $entries
            ->groupBy(fn ($e) => $e->sku.'|'.$e->bin)
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'sku' => $first->sku,
                    'bin' => $first->bin,
                    'available' => $group->where('bucket', 'available')->sum('qty'),
                    'defective' => $group->where('bucket', 'defective')->sum('qty'),
                    'rejected' => $group->where('bucket', 'rejected')->sum('qty'),
                    'qcHold' => $group->where('bucket', 'qcHold')->sum('qty'),
                ];
            })
            ->values();

        return ['balance' => $balance];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Stock Balance</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Current quantity per SKU, aggregated across every ledger posting.</p>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-4 py-2.5 font-medium">SKU</th>
                        <th class="px-4 py-2.5 font-medium">Bin</th>
                        <th class="px-4 py-2.5 font-medium">Available</th>
                        <th class="px-4 py-2.5 font-medium">QC Hold</th>
                        <th class="px-4 py-2.5 font-medium">Defective</th>
                        <th class="px-4 py-2.5 font-medium">Rejected</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($balance as $b)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $b['sku'] }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ $b['bin'] }}</td>
                            <td class="px-4 py-2.5" style="color: var(--status-good);">{{ $b['available'] }}</td>
                            <td class="px-4 py-2.5" style="color: var(--status-warning);">{{ $b['qcHold'] }}</td>
                            <td class="px-4 py-2.5" style="color: var(--status-critical);">{{ $b['defective'] }}</td>
                            <td class="px-4 py-2.5" style="color: var(--status-critical);">{{ $b['rejected'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No stock posted yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
