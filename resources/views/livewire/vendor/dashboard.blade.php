<?php
use App\Models\AuditLogEntry;
use App\Models\FinanceRecord;
use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderAcknowledgement;
use App\Models\QcResult;
use App\Models\Rfq;
use App\Models\ValidationIssue;
use App\Models\VendorSubmission;
use App\Services\AuditLogger;
use App\Services\PurchaseOrderProgressService;
use App\Support\CombinedActivityFeed;
use App\Support\VendorIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    private const ACTIVITY_KEYWORDS = ['Vendor submission', 'Vendor stock', 'Vendor User', 'Purchase return', 'RFQ'];

    public bool $showAllActivity = false;

    public string $analyticsFrom = '';

    public string $analyticsTo = '';

    #[Validate('required|string|max:255')]
    public string $rfqSku = '';

    #[Validate('required|integer|min:1')]
    public ?int $rfqQuantity = null;

    #[Validate('nullable|string|max:1000')]
    public string $rfqNotes = '';

    public ?string $decidingPo = null;

    public string $remarks = '';

    protected function validationAttributes(): array
    {
        return ['rfqSku' => 'SKU/Material', 'rfqQuantity' => 'Quantity', 'rfqNotes' => 'Notes'];
    }

    public function submitRfq(): void
    {
        $this->validate();

        $rfq = Rfq::create([
            'vendor_name' => auth()->user()->name,
            'sku' => $this->rfqSku,
            'quantity' => $this->rfqQuantity,
            'notes' => $this->rfqNotes ?: null,
        ]);

        AuditLogger::log('RFQ submitted', "{$rfq->sku} · qty {$rfq->quantity}", $rfq);

        $this->reset(['rfqSku', 'rfqQuantity', 'rfqNotes']);
        session()->flash('rfqSuccess', 'RFQ submitted successfully.');
    }

    public function clearAnalyticsFilter(): void
    {
        $this->reset(['analyticsFrom', 'analyticsTo']);
    }

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

    public function initiatePurchaseReturn(int $qcResultId): void
    {
        $user = auth()->user();
        $vendorName = $user->name;

        $result = QcResult::whereHas('gateEntry', fn ($q) => VendorIdentity::apply($q, auth()->user()))
            ->where('return_status', 'pending')
            ->findOrFail($qcResultId);

        $result->update(['return_status' => 'initiated', 'return_initiated_at' => now()]);

        AuditLogger::log('Purchase return initiated', "{$result->gateEntry?->gate_no} · {$vendorName} · rejected {$result->rejected_qty}", $result);
    }

    public function with(): array
    {
        $user = auth()->user();
        $vendorName = $user->name;

        // Confirmed live: this tile previously counted VendorSubmission rows
        // marked correction_requested -- an unrelated, rarely-used status --
        // while the actual open issues shown lower on this same page
        // (Duplicate Invoice, etc.) are ValidationIssue rows against this
        // vendor's gate entries. Showed "Issues Pending: 0" with 2 real open
        // issues on screen below it.
        $openIssuesCount = ValidationIssue::whereHas(
            'gateEntry',
            fn ($q) => VendorIdentity::apply($q, $user)
        )->where('status', 'open')->count();

        $activity = CombinedActivityFeed::forUser(
            auth()->user(),
            self::ACTIVITY_KEYWORDS,
            fn (AuditLogEntry $row) => $row->vendorName() === $vendorName,
        );
        $allSubmissions = VendorIdentity::apply(VendorSubmission::query(), $user)->get();
        // Confirmed live: this used to only ever look at PO numbers the
        // vendor had ALREADY submitted an invoice against -- a freshly
        // released PO with no submission yet was completely invisible here
        // ("Open (0)" on the dashboard even with a real PO waiting), so the
        // vendor had no way to discover it existed short of typing the PO
        // number by hand somewhere else. Released POs now seed the list too.
        $releasedPoNumbers = VendorIdentity::apply(PurchaseOrder::query(), $user)->whereNotNull('released_at')->pluck('po_number');
        $poNumbers = $allSubmissions->pluck('po_number')->filter()->merge($releasedPoNumbers)->unique();
        $purchaseOrders = VendorIdentity::apply(PurchaseOrder::query(), $user)
            ->whereIn('po_number', $poNumbers)
            ->with('lines')
            ->get()
            ->keyBy('po_number');
        $poLive = app(PurchaseOrderProgressService::class)->summarize($purchaseOrders->values(), VendorIdentity::isShared($user) ? null : $vendorName);
        $acknowledgements = VendorIdentity::apply(PurchaseOrderAcknowledgement::query(), $user)
            ->whereIn('po_number', $poNumbers)
            ->get()
            ->keyBy('po_number');
        $pendingAcknowledgementPos = $purchaseOrders
            ->filter(fn (PurchaseOrder $po) => $po->released_at
                && ! $acknowledgements->has($po->po_number)
                && ! in_array($po->status, ['Delivered', 'Cancelled'], true))
            ->sortByDesc(fn (PurchaseOrder $po) => $po->released_at?->timestamp ?? $po->created_at?->timestamp ?? 0)
            ->values();
        $latestSubmissionByPo = $allSubmissions
            ->sortByDesc('created_at')
            ->groupBy('po_number')
            ->map(fn ($rows) => $rows->first());
        $acceptedPos = $purchaseOrders
            ->filter(fn (PurchaseOrder $po) => $acknowledgements->get($po->po_number)?->accepted === true)
            ->sortByDesc(fn (PurchaseOrder $po) => ($po->released_at ?? $po->created_at)?->timestamp ?? 0)
            ->take(8)
            ->values();
        $recentPos = $purchaseOrders
            ->sortByDesc(fn (PurchaseOrder $po) => ($po->released_at ?? $po->created_at)?->timestamp ?? 0)
            ->take(8)
            ->values();
        $todaysOrders = $recentPos
            ->filter(fn (PurchaseOrder $po) => $po->released_at?->isToday() || (! $po->released_at && $po->created_at?->isToday()))
            ->values();
        $billShareUrls = $allSubmissions->mapWithKeys(fn (VendorSubmission $submission) => [
            $submission->id => URL::temporarySignedRoute(
                'vendor.bill.share',
                now()->addDay(),
                ['submission' => $submission->id],
            ),
        ]);

        // Confirmed live: "fulfilled" was purely the invoiced quantity, so a
        // PO with 100 invoiced but only 95 QC-accepted still read
        // "100/100 fulfilled" after GRN closed it. Once QC has actually run
        // for a PO, its accepted quantity is the real fulfilled figure;
        // before that, the invoiced quantity is still the best estimate.
        $acceptedByPo = QcResult::whereHas('gateEntry', fn ($q) => VendorIdentity::apply($q, $user)->whereNotNull('po_number'))
            ->with('gateEntry:id,po_number')
            ->get()
            ->groupBy(fn ($qc) => $qc->gateEntry?->po_number)
            ->map(fn ($group) => $group->sum('accepted_qty'));

        $fulfillment = $poNumbers->map(function ($po) use ($allSubmissions, $purchaseOrders, $acceptedByPo) {
            $ordered = (float) ($purchaseOrders->get($po)?->lines->sum('quantity') ?? 0);
            $fulfilled = $acceptedByPo->has($po) ? (float) $acceptedByPo->get($po) : (float) $allSubmissions->where('po_number', $po)->sum('invoice_qty');

            return [
                'po_number' => $po,
                'ordered' => $ordered,
                'fulfilled' => $fulfilled,
                'remaining' => max($ordered - $fulfilled, 0),
                'over' => $fulfilled > $ordered,
                'invoice_count' => $allSubmissions->where('po_number', $po)->count(),
                'pct' => $ordered > 0 ? min(100, (int) round($fulfilled / $ordered * 100)) : 0,
            ];
        })->filter(fn ($f) => $f['ordered'] > 0)->values();

        $allFinanceRecords = VendorIdentity::apply(FinanceRecord::query(), $user)->with('gateEntry')->orderByDesc('created_at')->get();
        $financePoNumbers = $allFinanceRecords->pluck('gateEntry.po_number')->filter()->unique();
        $poDueDates = PurchaseOrder::whereIn('po_number', $financePoNumbers)->pluck('due_date', 'po_number');

        // Paid / Raised / Overdue — grouped by the PO's own due date (its
        // payment terms), not just the raw pending/cleared/hold status.
        $financeByStatus = $allFinanceRecords->groupBy(function ($fr) use ($poDueDates) {
            if ($fr->vendor_status === 'cleared') {
                return 'paid';
            }

            $due = $poDueDates->get($fr->gateEntry?->po_number);

            return ($due && $due->isPast()) ? 'overdue' : 'raised';
        });

        $financialGroups = [
            ['key' => 'paid', 'label' => 'Paid', 'color' => 'good', 'records' => $financeByStatus->get('paid', collect())],
            ['key' => 'raised', 'label' => 'Raised', 'color' => 'warning', 'records' => $financeByStatus->get('raised', collect())],
            ['key' => 'overdue', 'label' => 'Overdue', 'color' => 'critical', 'records' => $financeByStatus->get('overdue', collect())],
        ];

        $analyticsFrom = $this->analyticsFrom ? Carbon::parse($this->analyticsFrom)->startOfDay() : null;
        $analyticsTo = $this->analyticsTo ? Carbon::parse($this->analyticsTo)->endOfDay() : null;

        $filteredFinance = $allFinanceRecords->filter(
            fn ($fr) => (! $analyticsFrom || $fr->created_at->gte($analyticsFrom))
                && (! $analyticsTo || $fr->created_at->lte($analyticsTo))
        );

        // Confirmed live: Recent Submissions' Status column showed the
        // submission's own static "submitted" badge forever, with no way
        // to tell the underlying gate entry had actually closed.
        $gateStatusByPo = VendorIdentity::apply(GateEntry::query(), $user)
            ->whereIn('po_number', $poNumbers)
            ->orderByDesc('created_at')
            ->get(['po_number', 'status'])
            ->unique('po_number')
            ->keyBy('po_number');

        $grnReports = VendorIdentity::apply(GateEntry::query(), $user)
            ->whereIn('status', ['grn_posted', 'closed'])
            ->whereHas('grnRecord', fn ($q) => $q->where('posted', true)->where('qc_hold_qty', 0))
            ->with('grnRecord')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        return [
            // Gate entries the Guard logged against this vendor's name, shown
            // regardless of whether the vendor pre-filed a matching
            // submission — previously a gate entry was invisible here unless
            // the vendor had already submitted first, so a Guard-only entry
            // never reached the vendor at all.
            'gateActivity' => VendorIdentity::apply(GateEntry::query(), $user)->orderByDesc('created_at')->take(10)->get(),
            'grnReports' => $grnReports,
            'gateStatusByPo' => $gateStatusByPo,
            'poLive' => $poLive,
            'recentPos' => $recentPos,
            'todaysOrders' => $todaysOrders,
            'pendingAcknowledgementPos' => $pendingAcknowledgementPos,
            'latestSubmissionByPo' => $latestSubmissionByPo,
            'acceptedPos' => $acceptedPos,
            'billShareUrls' => $billShareUrls,
            'acknowledgements' => $acknowledgements,
            'openIssuesCount' => $openIssuesCount,
            'submissions' => $allSubmissions->sortByDesc('created_at')->take(5)->values(),
            'fulfillment' => $fulfillment,
            'openPos' => $fulfillment->where('remaining', '>', 0)->values(),
            'closedPos' => $fulfillment->where('remaining', '<=', 0)->values(),
            'financialGroups' => $financialGroups,
            'isAdvanced' => auth()->user()->isAdvancedVendor(),
            'activityTotal' => $activity->count(),
            'recentActivity' => $this->showAllActivity ? $activity : $activity->take(5),
            'rfqs' => VendorIdentity::apply(Rfq::query(), $user)->orderByDesc('created_at')->take(5)->get(),
            'pendingReturns' => QcResult::whereHas('gateEntry', fn ($q) => VendorIdentity::apply($q, $user))
                ->where('return_status', 'pending')
                ->with('gateEntry')
                ->orderByDesc('created_at')
                ->get(),
            'totalPos' => $fulfillment->count(),
            'totalRevenue' => $filteredFinance->sum('invoice_value'),
            'clearedPos' => $filteredFinance->where('vendor_status', 'cleared')->count(),
            'pendingPos' => $filteredFinance->whereIn('vendor_status', ['pending', 'hold'])->count(),
            'clearedPayables' => $filteredFinance->where('vendor_status', 'cleared')->sum('final_payable'),
        ];
    }
}; ?>

<div>
    <section class="rounded-2xl border p-5 mb-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Vendor Module</div>
        <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">Dashboard</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">Overview of your activities.</p>
    </section>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <div class="rounded-2xl border p-4 flex items-center gap-3.5" style="background: var(--surface-3); border-color: var(--border);">
            <div class="w-11 h-11 shrink-0 rounded-xl grid place-items-center" style="background: var(--brand-bg); color: var(--brand);">
                <x-icon name="file-text" class="w-6 h-6" />
            </div>
            <div>
                <div class="text-2xl font-bold" style="color: var(--text-primary);">{{ \App\Support\VendorIdentity::apply(\App\Models\VendorSubmission::query(), auth()->user())->count() }}</div>
                <div class="text-xs" style="color: var(--text-muted);">Total Submissions</div>
            </div>
        </div>
        <div class="rounded-2xl border p-4 flex items-center gap-3.5" style="background: var(--surface-3); border-color: var(--border);">
            <div class="w-11 h-11 shrink-0 rounded-xl grid place-items-center" style="background: var(--status-critical-bg); color: var(--status-critical);">
                <x-icon name="shield-alert" class="w-6 h-6" />
            </div>
            <div>
                <div class="text-2xl font-bold" style="color: var(--status-critical);">{{ $openIssuesCount }}</div>
                <div class="text-xs" style="color: var(--text-muted);">Issues Pending</div>
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-xl border px-4 py-3 mb-6 text-sm font-medium" style="background: var(--status-good-bg); border-color: var(--status-good); color: var(--status-good);" role="status">{{ session('success') }}</div>
    @endif

    <section class="rounded-2xl border p-4 mb-6" style="background: var(--surface-3); border-color: var(--brand);">
        <div class="flex items-start justify-between gap-3 mb-3">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Action center</div>
                <h2 class="text-lg font-semibold mt-1" style="color: var(--text-primary);">POs waiting for your response</h2>
                <p class="text-xs mt-1" style="color: var(--text-secondary);">Accept to start production or reject before dispatch.</p>
            </div>
            <a href="{{ route('vendor.purchase-orders') }}" wire:navigate class="text-xs font-semibold shrink-0" style="color: var(--brand);">Open all POs →</a>
        </div>
        <div class="space-y-3">
            @forelse ($pendingAcknowledgementPos as $po)
                @php($isNew = ($po->released_at ?? $po->created_at)?->gte(now()->subDays(2)))
                <div wire:key="vendor-pending-po-{{ \Illuminate\Support\Str::slug($po->po_number) }}" class="rounded-xl border p-4" style="border-color: var(--border); background: var(--surface-2);">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-sm break-all" style="color: var(--text-primary);">{{ $po->po_number }}</span>
                                @if ($isNew)
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide" style="background: #fce7f3; color: #be185d;">New</span>
                                @endif
                            </div>
                            <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $po->subject ?: 'Purchase order' }} · Released {{ ($po->released_at ?? $po->created_at)?->format('d M Y, H:i') }}</div>
                            <div class="text-xs mt-1" style="color: var(--text-muted);">{{ $po->lines->count() }} SKU(s) · ₹{{ number_format($po->grandTotal(), 2) }}</div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 shrink-0">
                            <button type="button" wire:click="acknowledge('{{ $po->po_number }}', true)" wire:loading.attr="disabled" wire:target="acknowledge('{{ $po->po_number }}', true)" class="inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-sm font-bold text-white transition-colors hover:opacity-90 focus:outline-none focus-visible:ring-2 disabled:opacity-50" style="background: var(--status-good); --tw-ring-color: var(--status-good);">Accept PO</button>
                            <button type="button" wire:click="acknowledge('{{ $po->po_number }}', false)" wire:confirm="Reject {{ $po->po_number }}? This will notify the Store Manager." wire:loading.attr="disabled" wire:target="acknowledge('{{ $po->po_number }}', false)" class="inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2.5 text-sm font-bold text-white transition-colors hover:opacity-90 focus:outline-none focus-visible:ring-2 disabled:opacity-50" style="background: var(--status-critical); --tw-ring-color: var(--status-critical);">Reject PO</button>
                            <a href="{{ route('vendor.purchase-orders') }}#vendor-po-{{ \Illuminate\Support\Str::slug($po->po_number) }}" wire:navigate class="inline-flex min-h-11 items-center rounded-xl border px-4 py-2.5 text-sm font-semibold transition-colors hover:bg-black/5 focus:outline-none focus-visible:ring-2" style="border-color: var(--border); color: var(--text-primary); --tw-ring-color: var(--brand);">View details</a>
                        </div>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border p-5 text-center text-sm" style="border-color: var(--border); color: var(--text-muted);">No POs are waiting for your response.</div>
            @endforelse
        </div>
    </section>

    <section class="rounded-2xl border p-4 mb-6" style="background: var(--surface-3); border-color: var(--status-good);">
        <div class="flex items-start justify-between gap-3 mb-3">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--status-good);">Accepted queue</div>
                <h2 class="text-lg font-semibold mt-1" style="color: var(--text-primary);">Accepted POs — next action</h2>
                <p class="text-xs mt-1" style="color: var(--text-secondary);">Accepted orders stay here until dispatch is submitted, so you never need to search for them again.</p>
            </div>
            <a href="{{ route('vendor.submissions') }}" wire:navigate class="inline-flex min-h-11 items-center rounded-xl border px-3 py-2 text-xs font-bold shrink-0" style="border-color: var(--status-good); color: var(--status-good);">Open My Submissions →</a>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
            @forelse ($acceptedPos as $po)
                @php($live = $poLive->get($po->po_number))
                @php($submission = $latestSubmissionByPo->get($po->po_number))
                <div wire:key="vendor-accepted-po-{{ \Illuminate\Support\Str::slug($po->po_number) }}" class="rounded-xl border p-4" style="border-color: var(--status-good); background: var(--surface-2);">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-bold text-sm break-all" style="color: var(--text-primary);">{{ $po->po_number }}</span>
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide" style="background: var(--status-good-bg); color: var(--status-good);">Accepted</span>
                            </div>
                            <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $po->subject ?: 'Purchase order' }} · {{ $po->lines->count() }} SKU(s)</div>
                        </div>
                        <span class="text-xs font-semibold text-right shrink-0" style="color: var(--status-good);">{{ $live['stage'] ?? 'Accepted' }}</span>
                    </div>
                    <div class="mt-3 rounded-lg border px-3 py-2 text-xs" style="border-color: var(--border); background: var(--surface-1); color: var(--text-secondary);">
                        <strong style="color: var(--text-primary);">Next:</strong> {{ $live['next_action']['label'] ?? 'Upload invoice and dispatch documents' }}
                    </div>
                    <div class="flex flex-wrap gap-3 mt-3 text-xs font-semibold">
                        @if (! $submission)
                            <a href="{{ route('vendor.submissions') }}?po={{ urlencode($po->po_number) }}" wire:navigate style="color: var(--status-good);">Submit invoice / dispatch →</a>
                        @else
                            <a href="{{ route('vendor.submission-activity', $submission->id) }}" wire:navigate style="color: var(--brand);">Open dispatch details →</a>
                        @endif
                        <a href="{{ route('vendor.purchase-orders') }}#vendor-po-{{ \Illuminate\Support\Str::slug($po->po_number) }}" wire:navigate style="color: var(--brand);">View PO</a>
                        <a href="{{ route('vendor.po.pdf', $po->po_number) }}" target="_blank" rel="noopener" style="color: var(--brand);">PO PDF + QR</a>
                        <a href="{{ route('vendor.po.excel', $po->po_number) }}" style="color: var(--brand);">Download Excel</a>
                    </div>
                </div>
            @empty
                <div class="lg:col-span-2 rounded-xl border p-5 text-center text-sm" style="border-color: var(--border); color: var(--text-muted);">No accepted PO yet. Accept a released PO and it will appear here immediately.</div>
            @endforelse
        </div>
    </section>

    <section class="rounded-2xl border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-start justify-between gap-3 mb-3">
            <div>
                <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Today&apos;s orders</h2>
                <p class="text-xs mt-1" style="color: var(--text-secondary);">POs released today, with the latest live stage and document actions.</p>
            </div>
            <a href="{{ route('vendor.purchase-orders') }}" wire:navigate class="text-xs font-semibold shrink-0" style="color: var(--brand);">All POs →</a>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
            @forelse ($todaysOrders as $po)
                @php($live = $poLive->get($po->po_number))
                @php($submission = $latestSubmissionByPo->get($po->po_number))
                @php($isNew = ($po->released_at ?? $po->created_at)?->gte(now()->subDays(2)))
                <div class="rounded-xl border p-4" style="border-color: var(--border); background: var(--surface-2);">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="font-semibold text-sm break-all" style="color: var(--text-primary);">{{ $po->po_number }}</div>
                                @if ($isNew)
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide" style="background: #fce7f3; color: #be185d;">New</span>
                                @endif
                            </div>
                            <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $po->subject ?: 'Purchase order' }} · {{ $po->released_at?->format('d M, H:i') ?? $po->created_at?->format('d M, H:i') }}</div>
                        </div>
                        <span class="text-xs font-semibold shrink-0" style="color: var(--brand);">{{ $live['stage'] ?? 'Released' }}</span>
                    </div>
                    <div class="mt-3 space-y-1 text-xs" style="color: var(--text-secondary);">
                        @foreach ($po->lines as $line)
                            <div class="flex items-center justify-between gap-2"><span class="truncate">{{ $line->documentProduct() }}</span><span class="shrink-0">{{ number_format((float) $line->quantity, 2) }} {{ $line->uom ?? 'PCS' }} · ₹{{ number_format((float) $line->list_price, 2) }}</span></div>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-3 mt-4 text-xs font-semibold">
                        <a href="{{ route('vendor.purchase-orders') }}#vendor-po-{{ \Illuminate\Support\Str::slug($po->po_number) }}" wire:navigate style="color: var(--brand);">View details</a>
                        <a href="{{ route('vendor.po.pdf', $po->po_number) }}" target="_blank" rel="noopener" style="color: var(--brand);">PO PDF + QR</a>
                        <a href="{{ route('vendor.po.excel', $po->po_number) }}" style="color: var(--brand);">PO Excel</a>
                        @if ($submission)
                            <a href="{{ $billShareUrls->get($submission->id) }}" target="_blank" rel="noopener" style="color: var(--brand);">Bill preview</a>
                            <a href="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('vendor.bill.share.excel', now()->addDay(), ['submission' => $submission->id]) }}" style="color: var(--brand);">Bill Excel</a>
                            <a href="{{ route('vendor.submission.pdf', $submission) }}" style="color: var(--brand);">Download bill</a>
                            <a href="{{ route('vendor.submission.excel', $submission) }}" style="color: var(--brand);">Bill Excel</a>
                        @else
                            <span style="color: var(--text-muted);">Bill pending dispatch</span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="lg:col-span-2 rounded-xl border p-5 text-center text-sm" style="border-color: var(--border); color: var(--text-muted);">No PO was released today.</div>
            @endforelse
        </div>
    </section>

    <section class="rounded-2xl border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-start justify-between gap-3 mb-3">
            <div>
                <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Recent POs</h2>
                <p class="text-xs mt-1" style="color: var(--text-secondary);">Latest released purchase orders connected to your vendor account.</p>
            </div>
            <a href="{{ route('vendor.purchase-orders') }}" wire:navigate class="text-xs font-semibold shrink-0" style="color: var(--brand);">Manage POs →</a>
        </div>
        <div class="space-y-2">
            @forelse ($recentPos as $po)
                @php($submission = $latestSubmissionByPo->get($po->po_number))
                @php($isNew = ($po->released_at ?? $po->created_at)?->gte(now()->subDays(2)))
                <div class="rounded-xl border p-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3" style="border-color: var(--border); background: var(--surface-2);">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="font-semibold text-sm break-all" style="color: var(--text-primary);">{{ $po->po_number }}</div>
                            @if ($isNew)
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide" style="background: #fce7f3; color: #be185d;">New</span>
                            @endif
                        </div>
                        <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $po->subject ?: 'Purchase order' }} · {{ $po->lines->count() }} SKU(s) · ₹{{ number_format($po->grandTotal(), 2) }}</div>
                    </div>
                    <div class="flex flex-wrap gap-3 text-xs font-semibold shrink-0">
                        <a href="{{ route('vendor.purchase-orders') }}#vendor-po-{{ \Illuminate\Support\Str::slug($po->po_number) }}" wire:navigate style="color: var(--brand);">Details</a>
                        <a href="{{ route('vendor.po.pdf', $po->po_number) }}" target="_blank" rel="noopener" style="color: var(--brand);">PO PDF</a>
                        <a href="{{ route('vendor.po.excel', $po->po_number) }}" style="color: var(--brand);">PO Excel</a>
                        @if ($submission)
                            <a href="{{ $billShareUrls->get($submission->id) }}" target="_blank" rel="noopener" style="color: var(--brand);">Bill preview</a>
                            <a href="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('vendor.bill.share.excel', now()->addDay(), ['submission' => $submission->id]) }}" style="color: var(--brand);">Bill Excel</a>
                            <a href="{{ route('vendor.submission.pdf', $submission) }}" style="color: var(--brand);">Download bill</a>
                            <a href="{{ route('vendor.submission.excel', $submission) }}" style="color: var(--brand);">Bill Excel</a>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm py-4 text-center" style="color: var(--text-muted);">No released POs yet.</p>
            @endforelse
        </div>
    </section>

    @if ($pendingReturns->isNotEmpty())
        <h2 class="text-lg font-semibold mb-3" style="color: var(--status-critical);">Purchase Returns Needed</h2>
        <div class="space-y-2 mb-6">
            @foreach ($pendingReturns as $r)
                <div class="rounded-lg border p-4 flex items-center justify-between gap-3" style="background: var(--surface-3); border-color: var(--status-critical);">
                    <div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $r->gateEntry?->gate_no }} · {{ $r->sku }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--status-critical);">Rejected qty: {{ $r->rejected_qty }}</div>
                        @if ($r->qc_reasons)
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $r->qc_reasons }}</div>
                        @endif
                    </div>
                    <button wire:click="initiatePurchaseReturn({{ $r->id }})" wire:confirm="Initiate purchase return for this rejected quantity?" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white shrink-0" style="background: var(--status-critical);">Purchase Return</button>
                </div>
            @endforeach
        </div>
    @endif

    <section class="rounded-2xl border p-4 mb-6" style="background: var(--surface-3); border-color: var(--status-good);">
        <div class="flex items-start justify-between gap-3 mb-3">
            <div><h2 class="text-lg font-semibold" style="color: var(--text-primary);">GRN reports</h2><p class="text-xs mt-1" style="color: var(--text-secondary);">Completed GRNs are published automatically by PO number. Review accepted, rejected, defective, on-hold and missing quantities, download the report, and respond once without searching through gate history.</p></div>
        </div>
        <div class="space-y-2">
            @forelse ($grnReports as $grnGate)
                @php($grnResponse = $grnGate->grnRecord?->vendor_response)
                <div wire:key="vendor-grn-gate-{{ $grnGate->id }}" class="rounded-xl border p-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3" style="border-color: var(--border); background: var(--surface-2);">
                    <div><div class="flex flex-wrap items-center gap-2"><strong class="text-sm" style="color: var(--text-primary);">{{ $grnGate->po_number ?: $grnGate->gate_no }}</strong><span class="text-[11px] font-bold uppercase rounded-full px-2 py-0.5" style="background: {{ $grnResponse ? 'var(--status-good-bg)' : 'var(--status-warning-bg)' }}; color: {{ $grnResponse ? 'var(--status-good)' : 'var(--status-warning)' }};">{{ $grnResponse ? ucfirst($grnResponse) : 'Response pending' }}</span></div><div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $grnGate->gate_no }} · Accepted {{ $grnGate->grnRecord?->accepted_qty }} · Rejected {{ $grnGate->grnRecord?->rejected_qty }} · Defective {{ $grnGate->grnRecord?->defective_qty }} · On Hold {{ $grnGate->grnRecord?->qc_hold_qty }} · Missing {{ $grnGate->grnRecord?->missing_qty }}</div></div>
                    <div class="flex flex-wrap gap-3 text-xs font-semibold shrink-0"><a href="{{ route('vendor.grn-report', $grnGate) }}" wire:navigate style="color: var(--brand);">{{ ((int) ($grnGate->grnRecord?->rejected_qty ?? 0) + (int) ($grnGate->grnRecord?->defective_qty ?? 0) + (int) ($grnGate->grnRecord?->missing_qty ?? 0)) > 0 && ! $grnResponse ? 'Upload CN/DN' : 'Review GRN' }}</a><a href="{{ route('grn.export.pdf', $grnGate) }}" style="color: var(--brand);">PDF</a><a href="{{ route('grn.export.excel', $grnGate) }}" style="color: var(--brand);">Excel</a></div>
                </div>
            @empty
                <div class="text-sm py-4 text-center" style="color: var(--text-muted);">No posted GRN is waiting for vendor review.</div>
            @endforelse
        </div>
    </section>

    <div class="flex items-center justify-between mb-3">
        <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Recent Gate Activity</h2>
    </div>
    <p class="text-xs mb-3 -mt-2" style="color: var(--text-secondary);">Every gate entry logged against your name, including ones the Guard recorded before you submitted anything.</p>
    <div class="rounded-lg border overflow-hidden mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="px-4 py-2.5 font-medium">Gate No</th>
                    <th class="px-4 py-2.5 font-medium">PO Number</th>
                    <th class="px-4 py-2.5 font-medium">Status</th>
                    <th class="px-4 py-2.5 font-medium">Date</th>
                    <th class="px-4 py-2.5 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($gateActivity as $g)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2.5 font-medium">
                            <a href="{{ route('gate-entries.show', $g) }}" wire:navigate style="color: var(--brand);">{{ $g->gate_no }}</a>
                        </td>
                        <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $g->po_number ?: '—' }}</td>
                        <td class="px-4 py-2.5" style="color: {{ $g->status === 'closed' ? 'var(--status-good)' : ($g->status === 'pending_validation' ? 'var(--status-critical)' : 'var(--status-warning)') }};">{{ \App\Support\GateStatusLabels::label($g->status) }}</td>
                        <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $g->created_at->format('d M, Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No gate entries recorded against your name yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="flex items-center justify-between mb-3">
        <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Recent Submissions</h2>
        <a href="{{ route('vendor.submissions') }}" wire:navigate class="text-sm font-medium" style="color: var(--brand);">View all</a>
    </div>
    <div class="rounded-lg border overflow-hidden mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="px-4 py-2.5 font-medium">PO Number</th>
                    <th class="px-4 py-2.5 font-medium">Status</th>
                    <th class="px-4 py-2.5 font-medium">Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($submissions as $sub)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2.5 font-medium">
                            <a href="{{ route('vendor.submission-activity', $sub->id) }}" wire:navigate style="color: var(--brand);">{{ $sub->po_number }}</a>
                        </td>
                        <td class="px-4 py-2.5" style="color: {{ $sub->status == 'submitted' ? 'var(--status-good)' : 'var(--status-critical)' }};">
                            {{ ucfirst(str_replace('_', ' ', $sub->status)) }}
                            @if ($gateStatusByPo->get($sub->po_number))
                                <div class="text-xs" style="color: var(--text-muted);">Gate: {{ \App\Support\GateStatusLabels::label($gateStatusByPo->get($sub->po_number)->status) }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $sub->created_at->format('d M, Y') }}</td>
                        <td class="px-4 py-2.5">
                            <div class="flex flex-wrap gap-2 text-xs font-semibold">
                                <a href="{{ route('vendor.submission-activity', $sub->id) }}" wire:navigate style="color: var(--brand);">Details</a>
                                <a href="{{ $billShareUrls->get($sub->id) }}" target="_blank" rel="noopener" style="color: var(--brand);">Bill preview</a>
                        <a href="{{ route('vendor.submission.pdf', $sub) }}" style="color: var(--brand);">Download</a>
                        <a href="{{ route('vendor.submission.excel', $sub) }}" style="color:var(--brand);">Download Excel</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No submissions found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">PO Fulfillment & live actions</h2>
    <p class="text-xs mb-3" style="color: var(--text-secondary);">Every released PO is connected to acknowledgement, split invoices, gate entry, QC, GRN and putaway. Open a card for the complete live trail.</p>

    @foreach ([['label' => 'Open', 'items' => $openPos, 'color' => 'warning'], ['label' => 'Closed', 'items' => $closedPos, 'color' => 'good']] as $group)
        <h3 class="text-sm font-semibold mb-2" style="color: var(--status-{{ $group['color'] }});">{{ $group['label'] }} ({{ $group['items']->count() }})</h3>
        <div class="space-y-3 mb-4">
            @forelse ($group['items'] as $f)
                @php($live = $poLive->get($f['po_number']))
                @php($ack = $acknowledgements->get($f['po_number']))
                <details id="vendor-po-{{ \Illuminate\Support\Str::slug($f['po_number']) }}" class="rounded-xl border group" style="background: var(--surface-3); border-color: var(--border);">
                    <summary class="list-none cursor-pointer p-4 focus:outline-none focus-visible:ring-2" style="--tw-ring-color: var(--brand);">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-semibold text-sm break-all" style="color: var(--text-primary);">{{ $f['po_number'] }}</div>
                                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $live['stage'] ?? 'Awaiting live update' }} · {{ $f['invoice_count'] }} invoice(s)</div>
                            </div>
                            <span class="text-xs font-semibold shrink-0" style="color: {{ $f['over'] ? 'var(--status-critical)' : 'var(--brand)' }};">{{ $f['pct'] }}% <span class="text-muted">⌄</span></span>
                        </div>
                        <div class="w-full h-2 rounded-full overflow-hidden mt-3" style="background: var(--surface-2);">
                            <div class="h-full rounded-full" style="width: {{ $f['pct'] }}%; background: {{ $f['over'] ? 'var(--status-critical)' : ($group['color'] === 'good' ? 'var(--status-good)' : 'var(--brand)') }};"></div>
                        </div>
                        <div class="flex items-center justify-between mt-1.5 text-xs" style="color: {{ $f['over'] ? 'var(--status-critical)' : 'var(--text-secondary)' }};">
                            <span>{{ rtrim(rtrim(number_format($f['fulfilled'], 2), '0'), '.') }} / {{ rtrim(rtrim(number_format($f['ordered'], 2), '0'), '.') }} fulfilled{{ $f['over'] ? ' — over-fulfilled' : '' }}</span>
                            <span>View details</span>
                        </div>
                    </summary>
                    <div class="border-t p-4" style="border-color: var(--border);">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                            <div><div style="color: var(--text-muted);">Current stage</div><strong style="color: var(--text-primary);">{{ $live['stage'] ?? '—' }}</strong></div>
                            <div><div style="color: var(--text-muted);">Next owner</div><strong style="color: var(--text-primary);">{{ $live['next_action']['owner'] ?? 'Operations' }}</strong></div>
                            <div><div style="color: var(--text-muted);">Billed amount</div><strong style="color: var(--text-primary);">₹{{ number_format((float) ($live['invoiced_amount'] ?? 0), 2) }}</strong></div>
                            <div><div style="color: var(--text-muted);">Open flags</div><strong style="color: {{ ($live['open_issue_count'] ?? 0) ? 'var(--status-critical)' : 'var(--status-good)' }};">{{ $live['open_issue_count'] ?? 0 }}</strong></div>
                        </div>
                        @if ($live['invoice_amount_review_required'] ?? false)
                            <aside role="alert" class="rounded-lg border p-3 mt-3 text-xs" style="border-color: var(--status-warning); background: var(--status-warning-bg); color: var(--status-warning);">
                                Split bills total ₹{{ number_format((float) $live['invoiced_amount'], 2) }} while the PO total is ₹{{ number_format((float) $live['po_total_amount'], 2) }} — ₹{{ number_format(abs((float) $live['invoice_amount_variance']), 2) }} {{ $live['invoice_amount_variance'] > 0 ? 'over' : 'under' }}. Please confirm the invoice totals and rounding with Procurement before closure. Existing bills have not been changed.
                            </aside>
                        @endif
                        @if (($live['next_action']['label'] ?? null))
                            <div class="rounded-lg border p-3 mt-3 text-xs" style="border-color: var(--border); background: var(--surface-2); color: var(--text-secondary);">Next action: <strong style="color: var(--text-primary);">{{ $live['next_action']['label'] }}</strong></div>
                        @endif
                        @if (! $ack && $live && ! in_array($live['po']->status, ['Delivered', 'Cancelled'], true))
                            <div class="mt-3 rounded-lg border px-3 py-2 text-xs" style="border-color: var(--border); background: var(--surface-2); color: var(--text-secondary);">Response pending — use the Action center above to accept or reject this PO.</div>
                        @elseif ($ack)
                            <div class="mt-3 text-xs font-semibold" style="color: {{ $ack->accepted ? 'var(--status-good)' : 'var(--status-critical)' }};">{{ $ack->accepted ? 'Accepted · production started' : 'Rejected' }} on {{ $ack->acknowledged_at?->format('d M Y, H:i') }}</div>
                        @endif
                        <div class="flex flex-wrap gap-3 mt-3 text-xs font-semibold">
                            <a href="{{ route('vendor.purchase-orders') }}#vendor-po-{{ \Illuminate\Support\Str::slug($f['po_number']) }}" wire:navigate style="color: var(--brand);">Open PO actions →</a>
                            <a href="{{ route('vendor.po.pdf', $f['po_number']) }}" target="_blank" style="color: var(--brand);">Download PO + QR PDF</a>
                            <a href="{{ route('vendor.po.excel', $f['po_number']) }}" style="color:var(--brand);">Download PO Excel</a>
                        </div>
                    </div>
                </details>
            @empty
                <p class="text-sm py-4 text-center rounded-lg border" style="color: var(--text-muted); border-color: var(--border); background: var(--surface-3);">No {{ strtolower($group['label']) }} POs.</p>
            @endforelse
        </div>
    @endforeach

    <h2 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">Financial Status</h2>
    <p class="text-xs mb-3" style="color: var(--text-secondary);">Every invoice raised against a closed PO, grouped by payment status per the PO's payment terms.</p>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
        @foreach ($financialGroups as $group)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-xs" style="color: var(--text-muted);">{{ $group['label'] }}</div>
                <div class="text-2xl font-semibold mt-1" style="color: var(--status-{{ $group['color'] }});">{{ $group['records']->count() }}</div>
            </div>
        @endforeach
    </div>

    @foreach ($financialGroups as $group)
        <h3 class="text-sm font-semibold mb-2" style="color: var(--status-{{ $group['color'] }});">{{ $group['label'] }} ({{ $group['records']->count() }})</h3>
        <div class="space-y-2 mb-4">
            @forelse ($group['records'] as $fr)
                <div class="rounded-lg border p-4 flex items-center justify-between text-sm" style="background: var(--surface-3); border-color: var(--border);">
                    <div>
                        <div style="color: var(--text-primary);">{{ $fr->invoice_number }} — Final payable: {{ number_format($fr->final_payable, 2) }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">Invoice value {{ number_format($fr->invoice_value, 2) }}{{ $fr->gateEntry?->po_number ? ' · '.$fr->gateEntry->po_number : '' }}</div>
                    </div>
                    <span class="text-xs font-semibold" style="color: var(--status-{{ $group['color'] }});">{{ $group['label'] }}</span>
                </div>
            @empty
                <p class="text-sm py-4 text-center rounded-lg border" style="color: var(--text-muted); border-color: var(--border); background: var(--surface-3);">No {{ strtolower($group['label']) }} invoices.</p>
            @endforelse
        </div>
    @endforeach

    <h2 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">Request for Quotation (RFQ)</h2>
    <div class="rounded-lg border p-4 mb-3" style="background: var(--surface-3); border-color: var(--border);">
        @if (session('rfqSuccess'))
            <div class="p-2 rounded-lg text-xs mb-3" style="background: var(--status-good-bg); color: var(--status-good);">{{ session('rfqSuccess') }}</div>
        @endif
        <form wire:submit="submitRfq" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">SKU/Material</span>
                <input wire:model="rfqSku" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                @error('rfqSku') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Quantity</span>
                <input wire:model="rfqQuantity" type="number" min="1" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                @error('rfqQuantity') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1.5 text-sm sm:col-span-2">
                <span class="font-medium" style="color: var(--text-primary);">Notes (optional)</span>
                <input wire:model="rfqNotes" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                @error('rfqNotes') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
            </label>
            <button type="submit" class="sm:col-span-4 rounded-lg px-3.5 py-2 text-sm font-medium text-white" style="background: var(--brand);">Add RFQ</button>
        </form>
    </div>
    <div class="rounded-lg border overflow-hidden mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="px-4 py-2.5 font-medium">SKU</th>
                    <th class="px-4 py-2.5 font-medium">Quantity</th>
                    <th class="px-4 py-2.5 font-medium">Status</th>
                    <th class="px-4 py-2.5 font-medium">Quoted Price</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rfqs as $rfq)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $rfq->sku }}</td>
                        <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $rfq->quantity }}</td>
                        <td class="px-4 py-2.5 capitalize" style="color: {{ $rfq->status === 'quoted' ? 'var(--status-good)' : ($rfq->status === 'closed' ? 'var(--text-muted)' : 'var(--status-warning)') }};">{{ $rfq->status }}</td>
                        <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $rfq->quoted_price ? '₹'.number_format($rfq->quoted_price, 2) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No RFQs submitted yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="rounded-lg border p-4 mb-6" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Activity</h2>
            @if ($activityTotal > 5)
                <button wire:click="$toggle('showAllActivity')" class="text-xs font-medium" style="color: var(--brand);">
                    {{ $showAllActivity ? 'Show less' : 'View all activity ('.$activityTotal.')' }}
                </button>
            @endif
        </div>
        <div class="flex flex-col divide-y" style="border-color: var(--border);">
            @forelse ($recentActivity as $row)
                <a href="{{ $row['url'] }}" wire:navigate class="py-3 flex items-center justify-between gap-3 -mx-2 px-2 rounded-lg hover:bg-black/5">
                    <div class="min-w-0">
                        <div class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ $row['title'] }}</div>
                        @if ($row['detail'])
                            <div class="text-xs mt-0.5 truncate" style="color: var(--text-muted);">{{ $row['detail'] }}</div>
                        @endif
                    </div>
                    <span class="text-xs shrink-0" style="color: var(--text-muted);">{{ $row['created_at']->format('d M, H:i') }}</span>
                </a>
            @empty
                <p class="text-sm py-4" style="color: var(--text-muted);">No activity recorded yet.</p>
            @endforelse
        </div>
    </div>

    @if ($isAdvanced)
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Advanced Analytics</h2>
            <div class="flex items-center gap-2 text-sm">
                <label class="flex items-center gap-1.5">
                    <span class="text-xs" style="color: var(--text-muted);">From</span>
                    <input type="date" wire:model.live="analyticsFrom" class="rounded-lg border px-2 py-1 text-xs" style="border-color: var(--border);" />
                </label>
                <label class="flex items-center gap-1.5">
                    <span class="text-xs" style="color: var(--text-muted);">To</span>
                    <input type="date" wire:model.live="analyticsTo" class="rounded-lg border px-2 py-1 text-xs" style="border-color: var(--border);" />
                </label>
                @if ($analyticsFrom || $analyticsTo)
                    <button wire:click="clearAnalyticsFilter" class="text-xs font-medium" style="color: var(--brand);">Clear</button>
                @endif
            </div>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-xs" style="color: var(--text-muted);">Avg PO fulfillment</div>
                <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $fulfillment->count() ? round($fulfillment->avg('pct')) : 0 }}%</div>
            </div>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-xs" style="color: var(--text-muted);">Total POs</div>
                <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $totalPos }}</div>
            </div>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-xs" style="color: var(--text-muted);">Open POs</div>
                <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $openPos->count() }}</div>
            </div>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-xs" style="color: var(--text-muted);">Total Revenue</div>
                <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">₹{{ number_format($totalRevenue, 2) }}</div>
            </div>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-xs" style="color: var(--text-muted);">Cleared POs</div>
                <div class="text-2xl font-semibold mt-1" style="color: var(--status-good);">{{ $clearedPos }}</div>
            </div>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-xs" style="color: var(--text-muted);">Pending POs</div>
                <div class="text-2xl font-semibold mt-1" style="color: var(--status-warning);">{{ $pendingPos }}</div>
            </div>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-xs" style="color: var(--text-muted);">Cleared payables</div>
                <div class="text-2xl font-semibold mt-1" style="color: var(--status-good);">₹{{ number_format($clearedPayables, 2) }}</div>
            </div>
        </div>
    @endif
</div>
