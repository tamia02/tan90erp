<?php

use App\Models\PurchaseOrder;
use App\Models\SupplierClaim;
use App\Services\AuditLogger;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Livewire\Attributes\Layout('layouts.app')] class extends Component
{
    #[Validate('nullable|string|max:255')]
    public string $po_number = '';

    #[Validate('required|string|max:2000')]
    public string $description = '';

    public function submit(): void
    {
        $this->validate();

        $claim = SupplierClaim::create([
            'po_number' => $this->po_number ?: null,
            'vendor_name' => auth()->user()->name,
            'description' => $this->description,
            'status' => 'open',
            'raised_by' => auth()->id(),
        ]);

        AuditLogger::log('Supplier claim raised', str($claim->description)->limit(60), $claim);

        $this->reset(['po_number', 'description']);
        session()->flash('success', 'Claim submitted.');
    }

    public function with(): array
    {
        $vendorName = auth()->user()->name;

        return [
            'claims' => SupplierClaim::where('vendor_name', $vendorName)->latest()->get(),
            'purchaseOrders' => PurchaseOrder::where('vendor_name', $vendorName)->orderByDesc('po_date')->limit(50)->get(),
        ];
    }
}; ?>

<div class="space-y-5">
    <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Vendor Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Claims &amp; Disputes</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Raise an issue against a PO — damaged goods, short delivery, a quality hold you disagree with.</p>
    </section>

    @if (session()->has('success'))
        <div class="p-3 rounded-lg text-sm" style="background: var(--status-good-bg); color: var(--status-good);">{{ session('success') }}</div>
    @endif

    <form wire:submit="submit" class="rounded-2xl border p-5 space-y-3" style="background: var(--surface-3); border-color: var(--border);">
        <select wire:model="po_number" class="w-full rounded-xl border px-3 py-2.5 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
            <option value="">Related PO (optional)</option>
            @foreach ($purchaseOrders as $po)
                <option value="{{ $po->po_number }}">{{ $po->po_number }} — {{ $po->subject }}</option>
            @endforeach
        </select>
        <textarea wire:model="description" placeholder="Describe the issue" class="w-full rounded-xl border px-3 py-2.5 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);"></textarea>
        @error('description') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
        <button class="rounded-xl px-4 py-2.5 text-sm font-bold text-white" style="background: var(--brand);">Submit Claim</button>
    </form>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
        @forelse ($claims as $claim)
            @php
                $claimColor = match ($claim->status) {
                    'resolved' => 'good',
                    'rejected' => 'critical',
                    default => 'warning',
                };
            @endphp
            <div class="rounded-2xl border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-start justify-between gap-3">
                    <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $claim->po_number ?? 'General' }}</div>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full shrink-0" style="background: var(--status-{{ $claimColor }}-bg); color: var(--status-{{ $claimColor }});">
                        {{ ucfirst($claim->status) }}
                    </span>
                </div>
                <p class="text-sm mt-1.5" style="color: var(--text-secondary);">{{ $claim->description }}</p>
                @if ($claim->resolution_notes)
                    <p class="text-xs mt-2 italic" style="color: var(--text-muted);">Response: {{ $claim->resolution_notes }}</p>
                @endif
            </div>
        @empty
            <p class="lg:col-span-2 text-sm text-center py-10" style="color: var(--text-muted);">No claims raised yet.</p>
        @endforelse
    </div>
</div>
