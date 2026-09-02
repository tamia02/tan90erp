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

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Claims &amp; Disputes</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Raise an issue against a PO — damaged goods, short delivery, a quality hold you disagree with.</p>

    @if (session()->has('success'))
        <div class="mb-4 p-3 rounded text-sm text-green-800 bg-green-100">{{ session('success') }}</div>
    @endif

    <form wire:submit="submit" class="p-4 rounded-lg border space-y-3 mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <select wire:model="po_number" class="w-full rounded border px-3 py-2 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
            <option value="">Related PO (optional)</option>
            @foreach ($purchaseOrders as $po)
                <option value="{{ $po->po_number }}">{{ $po->po_number }} — {{ $po->subject }}</option>
            @endforeach
        </select>
        <textarea wire:model="description" placeholder="Describe the issue" class="w-full rounded border px-3 py-2 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);"></textarea>
        @error('description') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
        <button class="rounded px-3.5 py-2 text-sm font-medium text-white" style="background: var(--primary);">Submit Claim</button>
    </form>

    <div class="space-y-3">
        @forelse ($claims as $claim)
            <div class="p-4 rounded-lg border" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-start justify-between gap-3">
                    <div class="text-sm" style="color: var(--text-primary);">{{ $claim->po_number ?? 'General' }}</div>
                    <span class="text-xs font-medium px-2 py-1 rounded" style="{{ $claim->status === 'resolved' ? 'background:#dcfce7;color:#166534' : ($claim->status === 'rejected' ? 'background:#fee2e2;color:#b91c1c' : 'background:#fef9c3;color:#854d0e') }}">
                        {{ ucfirst($claim->status) }}
                    </span>
                </div>
                <p class="text-sm mt-1" style="color: var(--text-secondary);">{{ $claim->description }}</p>
                @if ($claim->resolution_notes)
                    <p class="text-xs mt-2 italic" style="color: var(--text-muted);">Response: {{ $claim->resolution_notes }}</p>
                @endif
            </div>
        @empty
            <p class="text-sm text-center py-10" style="color: var(--text-muted);">No claims raised yet.</p>
        @endforelse
    </div>
</div>
