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

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Store Manager Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">GRN Register</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Every GRN posted so far.</p>
    </section>

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
                            <td class="px-4 py-2.5 font-medium">
                                @if ($r->gateEntry)
                                    <a href="{{ route('gate-entries.show', $r->gateEntry) }}" wire:navigate style="color: var(--brand);">{{ $r->gateEntry->gate_no }}</a>
                                @else
                                    <span style="color: var(--text-primary);">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $r->sku }}</td>
                            <td class="px-4 py-2.5">{{ $r->accepted_qty }}</td>
                            <td class="px-4 py-2.5">{{ $r->defective_qty }}</td>
                            <td class="px-4 py-2.5">{{ $r->rejected_qty }}</td>
                            <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">{{ $r->suggested_bin }}</td>
                            <td class="px-4 py-2.5 text-xs font-medium" style="color: {{ $r->posted ? 'var(--status-good)' : 'var(--status-warning)' }};">{{ $r->posted ? 'Put-away complete · stock reflected' : 'Pending' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No GRNs posted yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
