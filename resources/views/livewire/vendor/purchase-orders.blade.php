<?php

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderAcknowledgement;
use App\Models\GateEntry;
use App\Models\VendorSubmission;
use App\Services\AuditLogger;
use App\Support\GateStatusLabels;
use App\Support\VendorIdentity;
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
        $po = VendorIdentity::apply(PurchaseOrder::query(), auth()->user())
            ->where('po_number', $poNumber)->whereNotNull('released_at')->firstOrFail();
        $vendorName = $po->vendor_name;

        $ack = PurchaseOrderAcknowledgement::updateOrCreate(
            ['po_number' => $po->po_number],
            [
                'vendor_name' => $vendorName,
                'accepted' => $accepted,
                'remarks' => $this->remarks ?: null,
                'acknowledged_by' => auth()->id(),
                'acknowledged_at' => now(),
                'production_started_at' => $accepted ? now() : null,
            ],
        );

        AuditLogger::log($accepted ? 'PO acknowledged' : 'PO declined', "{$po->po_number} — {$vendorName}", $ack);

        $this->decidingPo = null;
        $this->remarks = '';
        session()->flash('success', ($accepted ? 'Accepted' : 'Declined')." {$po->po_number}.");
    }

    public function with(): array
    {
        $user = auth()->user();
        $vendorName = $user->name;

        // Draft POs (created in PO Master, not yet explicitly released) stay
        // invisible here -- a vendor should only see a PO once someone has
        // actually decided to hand it to them, not the moment it's typed in.
        $orders = VendorIdentity::apply(PurchaseOrder::query(), $user)
            ->whereNotNull('released_at')
            ->with('lines')
            // Release time is the operational truth. A newly released PO
            // must appear first even when its commercial PO date is older or
            // was left blank by the manager.
            ->orderByDesc('released_at')
            ->orderByDesc('created_at')
            ->paginate(15);

        $poNumbers = $orders->getCollection()->pluck('po_number');

        $acknowledgements = VendorIdentity::apply(PurchaseOrderAcknowledgement::query(), $user)
            ->whereIn('po_number', $poNumbers)
            ->get()
            ->keyBy('po_number');

        // Keep the PO list as the vendor's single source of truth.  A vendor
        // should be able to see the current state of every released PO here,
        // even when it has not yet submitted an invoice or reached the gate.
        // Every supporting query is scoped by vendor and the visible PO set.
        $submissionsByPo = VendorIdentity::apply(VendorSubmission::query(), $user)
            ->whereIn('po_number', $poNumbers)
            ->latest('created_at')
            ->get()
            ->groupBy('po_number');

        $gateEntriesByPo = VendorIdentity::apply(GateEntry::query(), $user)
            ->whereIn('po_number', $poNumbers)
            ->with(['unloadingRecord', 'qcResult', 'grnRecord'])
            ->latest('created_at')
            ->get()
            ->groupBy('po_number');

        $poStatus = $orders->getCollection()->mapWithKeys(function (PurchaseOrder $po) use ($acknowledgements, $submissionsByPo, $gateEntriesByPo) {
            $ack = $acknowledgements->get($po->po_number);
            $submissions = $submissionsByPo->get($po->po_number, collect());
            $latestSubmission = $submissions->first();
            $gates = $gateEntriesByPo->get($po->po_number, collect());
            $latestGate = $gates->first();

            $ordered = (float) $po->lines->sum('quantity');
            $acceptedQty = (float) $gates->sum(fn (GateEntry $gate) => (float) ($gate->qcResult?->accepted_qty ?? 0));
            $invoiceQty = (float) $submissions->sum(fn (VendorSubmission $submission) => (float) ($submission->invoice_qty ?? 0));
            $hasQc = $gates->contains(fn (GateEntry $gate) => $gate->qcResult !== null);
            $fulfilled = $hasQc ? $acceptedQty : $invoiceQty;

            if ($latestGate) {
                $stage = GateStatusLabels::label($latestGate->status);
            } elseif ($latestSubmission?->dispatched_at) {
                $stage = 'Dispatched — awaiting gate entry';
            } elseif ($ack?->accepted) {
                $stage = 'Awaiting vendor dispatch';
            } elseif ($ack) {
                $stage = 'PO declined';
            } else {
                $stage = 'Awaiting PO response';
            }

            $tat = 'Not started';
            if ($latestSubmission?->dispatched_at && $latestSubmission->tat_due_at) {
                $tat = $latestSubmission->dispatched_at->lte($latestSubmission->tat_due_at) ? 'Met' : 'Missed';
            } elseif ($latestSubmission?->dispatched_at) {
                $tat = 'Dispatched';
            }

            return [$po->po_number => [
                'stage' => $stage,
                'gate_number' => $latestGate?->gate_no,
                'dock' => $latestGate?->loading_dock ?? $latestSubmission?->dock_number,
                'bin' => $latestGate?->final_bin,
                'ordered' => $ordered,
                'fulfilled' => $fulfilled,
                'remaining' => max($ordered - $fulfilled, 0),
                'pct' => $ordered > 0 ? min(100, (int) round($fulfilled / $ordered * 100)) : 0,
                'tat' => $tat,
                'latest_submission_id' => $latestSubmission?->id,
            ]];
        });

        return ['orders' => $orders, 'acknowledgements' => $acknowledgements, 'poStatus' => $poStatus];
    }
}; ?>

<div class="space-y-5">
    <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Vendor Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">My Purchase Orders</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Review each released PO, acknowledge it, start production, and dispatch within the due date.</p>
    </section>

    @if (session()->has('success'))
        <div class="p-3 rounded-lg text-sm" style="background: var(--status-good-bg); color: var(--status-good);">{{ session('success') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
        @forelse ($orders as $po)
                @php($ack = $acknowledgements->get($po->po_number))
            @php($isNew = ($po->released_at ?? $po->created_at)?->gte(now()->subDays(2)))
            <div id="vendor-po-{{ \Illuminate\Support\Str::slug($po->po_number) }}" class="rounded-2xl border p-4 scroll-mt-6" style="background: var(--surface-3); border-color: var(--border);">
                @php($status = $poStatus->get($po->po_number))
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $po->po_number }} — {{ $po->subject }}</div>
                            @if ($isNew)
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide" style="background: #fce7f3; color: #be185d;">New</span>
                            @endif
                        </div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                            {{ optional($po->po_date)->format('d M Y') }} · Due {{ optional($po->due_date)->format('d M Y') }} · Total ₹{{ number_format($po->grandTotal(), 2) }}
                        </div>
                    </div>

                    @if ($ack)
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full shrink-0" style="background: var(--status-{{ $ack->accepted ? 'good' : 'critical' }}-bg); color: var(--status-{{ $ack->accepted ? 'good' : 'critical' }});">
                            {{ $ack->accepted ? 'Accepted · Production started' : 'Declined' }} {{ $ack->acknowledged_at->format('d M, H:i') }}
                        </span>
                    @endif
                </div>

                @if ($po->lines->isNotEmpty())
                    <div class="space-y-1 text-xs mt-2" style="color: var(--text-secondary);">
                        @foreach ($po->lines as $line)
                            <div>{{ $line->documentProduct() }} · qty {{ number_format((float) $line->quantity, 2) }} {{ $line->uom ?? 'PCS' }} @ ₹{{ number_format((float) $line->list_price, 2) }}</div>
                        @endforeach
                    </div>
                    @endif

                @if ($ack?->accepted)
                    <div class="mt-3 rounded-xl border p-3 text-xs" style="border-color: var(--border); background: var(--surface-2); color: var(--text-secondary);">
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            <span>Production: <strong style="color: var(--status-good);">Started {{ $ack->production_started_at?->format('d M, H:i') ?? '—' }}</strong></span>
                            <span>Dispatch TAT: <strong style="color: {{ $po->due_date && $po->due_date->isPast() ? 'var(--status-critical)' : 'var(--text-primary)' }};">{{ $po->due_date?->format('d M Y') ?? 'Not set' }}</strong></span>
                        </div>
                        <div class="mt-1">Next: upload the invoice, E-way Bill, and LR/POD from <a href="{{ route('vendor.submissions') }}" wire:navigate style="color: var(--brand);">My Submissions</a> when the vehicle is dispatched.</div>
                    </div>
                @endif

                @if ($status)
                    <div class="mt-3 rounded-xl border p-3" style="border-color: var(--border); background: var(--surface-2);">
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Live PO status</div>
                            @if ($status['latest_submission_id'])
                                <a href="{{ route('vendor.submission-activity', $status['latest_submission_id']) }}" wire:navigate class="text-xs font-semibold" style="color: var(--brand);">View dispatch history →</a>
                            @endif
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-2 text-xs">
                            <div>
                                <div style="color: var(--text-muted);">Current stage</div>
                                <strong style="color: var(--text-primary);">{{ $status['stage'] }}</strong>
                            </div>
                            <div>
                                <div style="color: var(--text-muted);">Fulfilment</div>
                                <strong style="color: var(--text-primary);">{{ number_format($status['fulfilled'], 0) }} / {{ number_format($status['ordered'], 0) }} ({{ $status['pct'] }}%)</strong>
                            </div>
                            <div>
                                <div style="color: var(--text-muted);">Remaining</div>
                                <strong style="color: var(--text-primary);">{{ number_format($status['remaining'], 0) }}</strong>
                            </div>
                            <div>
                                <div style="color: var(--text-muted);">Dispatch TAT</div>
                                <strong style="color: {{ $status['tat'] === 'Missed' ? 'var(--status-critical)' : 'var(--status-good)' }};">{{ $status['tat'] }}</strong>
                            </div>
                        </div>
                        @if ($status['gate_number'] || $status['dock'] || $status['bin'])
                            <div class="text-xs mt-2" style="color: var(--text-secondary);">
                                @if ($status['gate_number']) Gate {{ $status['gate_number'] }} @endif
                                @if ($status['dock']) · Dock {{ $status['dock'] }} @endif
                                @if ($status['bin']) · Put away bin {{ $status['bin'] }} @endif
                            </div>
                        @endif
                    </div>
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
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="acknowledge('{{ $po->po_number }}', true)" wire:loading.attr="disabled" wire:target="acknowledge('{{ $po->po_number }}', true)" class="inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-sm font-bold text-white transition-colors hover:opacity-90 focus:outline-none focus-visible:ring-2 disabled:opacity-50" style="background: var(--status-good); --tw-ring-color: var(--status-good);">Accept PO</button>
                        <button type="button" wire:click="acknowledge('{{ $po->po_number }}', false)" wire:confirm="Decline {{ $po->po_number }}? This will notify the Store Manager." wire:loading.attr="disabled" wire:target="acknowledge('{{ $po->po_number }}', false)" class="inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-sm font-bold text-white transition-colors hover:opacity-90 focus:outline-none focus-visible:ring-2 disabled:opacity-50" style="background: var(--status-critical); --tw-ring-color: var(--status-critical);">Decline PO</button>
                    </div>
                @endif
            </div>
        @empty
            <p class="lg:col-span-2 text-sm text-center py-10" style="color: var(--text-muted);">No purchase orders yet.</p>
        @endforelse
    </div>

    {{ $orders->links() }}
</div>
