<?php

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderAcknowledgement;
use App\Services\AuditLogger;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Livewire\Attributes\Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public ?string $decidingPo = null;

    #[Validate('nullable|string|max:2000')]
    public string $remarks = '';

    public function startDeciding(string $poNumber): void
    {
        $this->decidingPo = $poNumber;
        $this->remarks = '';
    }

    public function acknowledge(string $poNumber, bool $accepted): void
    {
        $vendorName = auth()->user()->name;

        $po = PurchaseOrder::where('po_number', $poNumber)->where('vendor_name', $vendorName)->firstOrFail();

        $ack = PurchaseOrderAcknowledgement::updateOrCreate(
            ['po_number' => $po->po_number],
            [
                'vendor_name' => $vendorName,
                'accepted' => $accepted,
                'remarks' => $this->remarks ?: null,
                'acknowledged_by' => auth()->id(),
                'acknowledged_at' => now(),
            ],
        );

        AuditLogger::log($accepted ? 'PO acknowledged' : 'PO declined', "{$po->po_number} — {$vendorName}", $ack);

        $this->decidingPo = null;
        $this->remarks = '';
        session()->flash('success', ($accepted ? 'Accepted' : 'Declined')." {$po->po_number}.");
    }

    public function with(): array
    {
        $vendorName = auth()->user()->name;

        $orders = PurchaseOrder::where('vendor_name', $vendorName)
            ->with('lines')
            ->latest('po_date')
            ->paginate(15);

        $acknowledgements = PurchaseOrderAcknowledgement::whereIn('po_number', $orders->pluck('po_number'))
            ->get()
            ->keyBy('po_number');

        return ['orders' => $orders, 'acknowledgements' => $acknowledgements];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">My Purchase Orders</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Review and accept or decline each PO.</p>

    @if (session()->has('success'))
        <div class="mb-4 p-3 rounded text-sm text-green-800 bg-green-100">{{ session('success') }}</div>
    @endif

    <div class="space-y-3">
        @forelse ($orders as $po)
            @php($ack = $acknowledgements->get($po->po_number))
            <div class="p-4 rounded-lg border" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $po->po_number }} — {{ $po->subject }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                            {{ optional($po->po_date)->format('d M Y') }} · Due {{ optional($po->due_date)->format('d M Y') }} · Total ₹{{ number_format($po->grandTotal(), 2) }}
                        </div>
                    </div>

                    @if ($ack)
                        <span class="text-xs font-medium px-2 py-1 rounded" style="{{ $ack->accepted ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#b91c1c' }}">
                            {{ $ack->accepted ? 'Accepted' : 'Declined' }} {{ $ack->acknowledged_at->format('d M, H:i') }}
                        </span>
                    @endif
                </div>

                @if ($po->primaryLine())
                    <div class="text-xs mt-2" style="color: var(--text-secondary);">{{ $po->primaryLine()->product }} · qty {{ $po->primaryLine()->quantity }} @ ₹{{ $po->primaryLine()->list_price }}</div>
                @endif

                @if ($ack?->remarks)
                    <div class="text-xs mt-2 italic" style="color: var(--text-muted);">"{{ $ack->remarks }}"</div>
                @endif

                @if (! $ack)
                    @if ($decidingPo === $po->po_number)
                        <div class="mt-3 space-y-2">
                            <textarea wire:model="remarks" placeholder="Remarks (optional)" class="w-full rounded border px-3 py-2 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);"></textarea>
                            <div class="flex gap-2">
                                <button wire:click="acknowledge('{{ $po->po_number }}', true)" class="rounded px-3 py-1.5 text-sm font-medium text-white bg-green-600">Accept</button>
                                <button wire:click="acknowledge('{{ $po->po_number }}', false)" class="rounded px-3 py-1.5 text-sm font-medium text-white bg-red-600">Decline</button>
                                <button wire:click="$set('decidingPo', null)" class="rounded px-3 py-1.5 text-sm border" style="border-color: var(--border); color: var(--text-secondary);">Cancel</button>
                            </div>
                        </div>
                    @else
                        <button wire:click="startDeciding('{{ $po->po_number }}')" class="mt-3 rounded px-3 py-1.5 text-sm font-medium border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">
                            Accept / Decline
                        </button>
                    @endif
                @endif
            </div>
        @empty
            <p class="text-sm text-center py-10" style="color: var(--text-muted);">No purchase orders yet.</p>
        @endforelse
    </div>

    {{ $orders->links() }}
</div>
