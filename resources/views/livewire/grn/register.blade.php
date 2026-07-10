<?php

use App\Models\GrnRecord;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function with(): array
    {
        return ['records' => GrnRecord::with('gateEntry')->orderByDesc('created_at')->get()];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">GRN Register</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Every GRN posted so far.</p>

    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="px-4 py-2.5 font-medium">Gate No</th>
                        <th class="px-4 py-2.5 font-medium">SKU</th>
                        <th class="px-4 py-2.5 font-medium">Accepted</th>
                        <th class="px-4 py-2.5 font-medium">Defective</th>
                        <th class="px-4 py-2.5 font-medium">Rejected</th>
                        <th class="px-4 py-2.5 font-medium">Bin</th>
                        <th class="px-4 py-2.5 font-medium">Posted</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $r)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $r->gateEntry?->gate_no }}</td>
                            <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $r->sku }}</td>
                            <td class="px-4 py-2.5">{{ $r->accepted_qty }}</td>
                            <td class="px-4 py-2.5">{{ $r->defective_qty }}</td>
                            <td class="px-4 py-2.5">{{ $r->rejected_qty }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ $r->suggested_bin }}</td>
                            <td class="px-4 py-2.5">{{ $r->posted ? 'Yes' : 'No' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No GRNs posted yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
