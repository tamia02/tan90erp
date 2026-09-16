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

        // Draft POs (created in PO Master, not yet explicitly released) stay
        // invisible here -- a vendor should only see a PO once someone has
        // actually decided to hand it to them, not the moment it's typed in.
        $orders = PurchaseOrder::where('vendor_name', $vendorName)
            ->whereNotNull('released_at')
            ->with('lines')
            ->latest('po_date')
            ->paginate(15);

        $acknowledgements = PurchaseOrderAcknowledgement::whereIn('po_number', $orders->pluck('po_number'))
            ->get()
            ->keyBy('po_number');

        return ['orders' => $orders, 'acknowledgements' => $acknowledgements];
    }
}; ?>

<div class="space-y-5">
    <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Vendor Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">My Purchase Orders</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Review and accept or decline each PO.</p>
    </section>

    @if (session()->has('success'))
        <div class="p-3 rounded-lg text-sm" style="background: var(--status-good-bg); color: var(--status-good);">{{ session('success') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
        @forelse ($orders as $po)
            @php($ack = $acknowledgements->get($po->po_number))
            <div class="rounded-2xl border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div>
                        <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $po->po_number }} — {{ $po->subject }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                            {{ optional($po->po_date)->format('d M Y') }} · Due {{ optional($po->due_date)->format('d M Y') }} · Total ₹{{ number_format($po->grandTotal(), 2) }}
                        </div>
                    </div>

                    @if ($ack)
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full shrink-0" style="background: var(--status-{{ $ack->accepted ? 'good' : 'critical' }}-bg); color: var(--status-{{ $ack->accepted ? 'good' : 'critical' }});">
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

                {{-- Confirmed live: PO 0020 (status Delivered, years into
                     fulfillment) still showed Accept/Decline since it
                     predates this feature and was never acknowledged --
                     accepting/declining a PO that's already been delivered
                     or cancelled is meaningless. --}}
                @if (! $ack && ! in_array($po->status, ['Delivered', 'Cancelled'], true))
                    @if ($decidingPo === $po->po_number)
                        <div class="mt-3 space-y-2">
                            <textarea wire:model="remarks" placeholder="Remarks (optional)" class="w-full rounded-xl border px-3 py-2.5 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);"></textarea>
                            <div class="flex gap-2">
                                <button wire:click="acknowledge('{{ $po->po_number }}', true)" class="rounded-xl px-3.5 py-2 text-sm font-bold text-white" style="background: var(--status-good);">Accept</button>
                                <button wire:click="acknowledge('{{ $po->po_number }}', false)" class="rounded-xl px-3.5 py-2 text-sm font-bold text-white" style="background: var(--status-critical);">Decline</button>
                                <button wire:click="$set('decidingPo', null)" class="rounded-xl px-3.5 py-2 text-sm font-semibold border" style="border-color: var(--border); color: var(--text-secondary);">Cancel</button>
                            </div>
                        </div>
                    @else
                        <button wire:click="startDeciding('{{ $po->po_number }}')" class="mt-3 rounded-xl px-3.5 py-2 text-sm font-semibold border" style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);">
                            Accept / Decline
                        </button>
                    @endif
                @endif
            </div>
        @empty
            <p class="lg:col-span-2 text-sm text-center py-10" style="color: var(--text-muted);">No purchase orders yet.</p>
        @endforelse
    </div>

    {{ $orders->links() }}
</div>
