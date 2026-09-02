<?php

use App\Models\SupplierClaim;
use App\Services\AuditLogger;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?int $responding = null;
    public string $resolutionNotes = '';

    public function respond(int $id): void
    {
        $claim = SupplierClaim::findOrFail($id);
        $this->responding = $claim->id;
        $this->resolutionNotes = (string) ($claim->resolution_notes ?? '');
    }

    public function resolve(int $id, string $status): void
    {
        $claim = SupplierClaim::findOrFail($id);

        $this->validate(['resolutionNotes' => ['required', 'string', 'max:2000']]);

        $claim->update([
            'status' => $status,
            'resolution_notes' => $this->resolutionNotes,
            'resolved_by' => auth()->id(),
            'resolved_at' => now(),
        ]);

        AuditLogger::log('Supplier claim '.$status, "{$claim->vendor_name} · ".str($claim->description)->limit(60), $claim);

        $this->reset(['responding', 'resolutionNotes']);
    }

    public function with(): array
    {
        return ['claims' => SupplierClaim::orderByRaw("field(status,'open','reviewing','resolved','rejected')")->latest()->get()];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Supplier Claims &amp; Disputes</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Issues vendors have raised against a PO — damaged goods, short delivery, disputed holds.</p>

    <div class="flex flex-col gap-3">
        @forelse ($claims as $claim)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $claim->vendor_name }} @if($claim->po_number) · {{ $claim->po_number }} @endif</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $claim->created_at->format('d M, Y H:i') }}</div>
                    </div>
                    <span class="text-xs font-medium capitalize px-2 py-0.5 rounded" style="background: var(--surface-2); color: {{ $claim->status === 'resolved' ? 'var(--status-good)' : ($claim->status === 'rejected' ? 'var(--status-critical)' : 'var(--status-warning)') }};">{{ $claim->status }}</span>
                </div>

                <div class="text-sm mt-2" style="color: var(--text-secondary);">{{ $claim->description }}</div>
                @if ($claim->resolution_notes)
                    <div class="text-xs mt-1" style="color: var(--text-muted);">Response: {{ $claim->resolution_notes }}</div>
                @endif

                @if ($responding === $claim->id)
                    <div class="mt-3 space-y-2">
                        <textarea wire:model="resolutionNotes" placeholder="Response / resolution notes" class="w-full rounded border px-3 py-2 text-sm" style="border-color: var(--border);"></textarea>
                        @error('resolutionNotes') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                        <div class="flex gap-2">
                            <button wire:click="resolve({{ $claim->id }}, 'resolved')" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--status-good); color: var(--status-good);">Mark Resolved</button>
                            <button wire:click="resolve({{ $claim->id }}, 'rejected')" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--status-critical); color: var(--status-critical);">Reject</button>
                            <button wire:click="$set('responding', null)" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border" style="border-color: var(--border); color: var(--text-secondary);">Cancel</button>
                        </div>
                    </div>
                @elseif (! in_array($claim->status, ['resolved', 'rejected']))
                    <button wire:click="respond({{ $claim->id }})" class="text-xs font-medium rounded-lg px-2.5 py-1.5 border mt-3" style="border-color: var(--border); color: var(--text-primary);">Respond</button>
                @endif
            </div>
        @empty
            <div class="text-center text-sm py-10" style="color: var(--text-muted);">No claims raised yet.</div>
        @endforelse
    </div>
</div>
