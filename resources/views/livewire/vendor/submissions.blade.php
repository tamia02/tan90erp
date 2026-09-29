<?php
use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderAcknowledgement;
use App\Models\QcResult;
use App\Models\ValidationIssue;
use App\Models\VendorSubmission;
use App\Services\AuditLogger;
use App\Services\PurchaseOrderAmountCalculator;
use App\Services\PurchaseOrderProgressService;
use App\Support\GateStatusLabels;
use App\Support\VendorIdentity;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public bool $adding = false;

    public string $tab = 'accepted';

    public array $selectedPoLines = [];

    public array $selectedPoSummary = [];

    public array $submissionLineQuantities = [];

    public function mount(): void
    {
        $requestedPo = request()->query('po');
        if (is_string($requestedPo) && $requestedPo !== '') {
            $this->startSubmission($requestedPo);
        }
    }

    public function startSubmission(string $poNumber): void
    {
        $this->adding = true;
        $this->tab = 'accepted';
        $this->po_number = $poNumber;
        $this->updatedPoNumber($poNumber);
    }

    public function clearSubmissionForm(): void
    {
        $this->reset(['po_number', 'invoice_number', 'invoice_date', 'invoice_qty', 'invoice_amount', 'material', 'expected_arrival_at', 'vehicle_number', 'invoice_file', 'eway_bill_file', 'lr_pod_file', 'dispatch_confirmation']);
        $this->selectedPoLines = [];
        $this->selectedPoSummary = [];
        $this->submissionLineQuantities = [];
        session()->flash('info', 'Submission form cleared. Select an accepted PO to start again.');
    }

    // The PO must exist locally; the vendor can only submit against an
    // accepted PO released from the local PO Master.
    #[Validate('required|string|max:255|exists:purchase_orders,po_number')]
    public string $po_number = '';

    #[Validate('nullable|string|max:255')]
    public string $invoice_number = '';

    #[Validate('required|integer|min:1')]
    public ?int $invoice_qty = null;

    #[Validate('nullable|date')]
    public string $invoice_date = '';

    #[Validate('nullable|numeric|min:0')]
    public ?float $invoice_amount = null;

    #[Validate('nullable|string|max:255')]
    public string $material = '';

    #[Validate('nullable|date|after:now')]
    public string $expected_arrival_at = '';

    #[Validate('nullable|string|max:255')]
    public string $vehicle_number = '';

    #[Validate('nullable|file|max:5120')]
    public $invoice_file;

    #[Validate('nullable|file|max:5120')]
    public $eway_bill_file;

    #[Validate('nullable|file|max:5120')]
    public $lr_pod_file;

    #[Validate('accepted')]
    public bool $dispatch_confirmation = false;

    /** Keep validation messages readable for the vendor filling this form. */
    protected function validationAttributes(): array
    {
        return [
            'po_number' => 'PO Number',
            'invoice_number' => 'Invoice Number',
            'invoice_qty' => 'Invoice Quantity',
            'invoice_date' => 'Invoice Date',
            'invoice_amount' => 'Invoice Amount',
            'material' => 'Material',
            'expected_arrival_at' => 'Expected Arrival',
            'vehicle_number' => 'Vehicle Number',
            'invoice_file' => 'Invoice File',
            'eway_bill_file' => 'E-way Bill',
            'lr_pod_file' => 'LR / POD',
            'dispatch_confirmation' => 'dispatch confirmation',
        ];
    }

    public function updatedPoNumber(?string $value): void
    {
        $this->selectedPoLines = [];
        $this->selectedPoSummary = [];
        $this->submissionLineQuantities = [];
        $this->material = '';
        $this->invoice_qty = null;
        $this->invoice_amount = null;
        if (! $value) {
            return;
        }

        $po = VendorIdentity::apply(PurchaseOrder::query(), auth()->user())
            ->where('po_number', $value)
            ->with('lines')
            ->first();
        if (! $po) {
            return;
        }

        $submissions = VendorIdentity::apply(VendorSubmission::query(), auth()->user())
            ->where('po_number', $po->po_number)
            ->get();
        [$billedByLine, $legacyUnallocatedQty] = $this->billedLineQuantities($po, $submissions);
        $this->selectedPoLines = $po->lines->map(function ($line) use ($billedByLine) {
            $billed = (float) ($billedByLine[$line->id] ?? 0);

            return [
                'id' => $line->id,
                'product' => $line->product,
                'ordered' => (float) $line->quantity,
                'uom' => $line->uom ?? '—',
                'unit_price' => (float) $line->list_price,
                'tax' => (float) ($line->tax ?? 0),
                'billed' => $billed,
                'remaining' => max((float) $line->quantity - $billed, 0),
            ];
        })->values()->all();
        $orderedQty = (float) $po->lines->sum('quantity');
        $alreadyBilled = (float) $submissions->sum('invoice_qty');
        $remainingQty = max($orderedQty - $alreadyBilled, 0);
        $this->selectedPoSummary = [
            'vendor' => $po->vendor_name,
            'po_date' => $po->po_date?->format('d M Y') ?? '—',
            'due_date' => $po->due_date?->format('d M Y') ?? '—',
            'status' => $po->status ?: 'Released',
            'ordered_qty' => $orderedQty,
            'remaining_qty' => $remainingQty,
            'total' => (float) $po->lines->sum(fn ($line) => (float) $line->quantity * (float) $line->list_price),
            'legacy_unallocated_qty' => $legacyUnallocatedQty,
        ];

        foreach ($po->lines as $line) {
            $knownBilled = (float) ($billedByLine[$line->id] ?? 0);
            $remainingForLine = max((float) $line->quantity - $knownBilled, 0);
            $hasAmbiguousLegacyBills = $legacyUnallocatedQty > 0;
            $this->submissionLineQuantities[$line->id] = $hasAmbiguousLegacyBills
                ? 0
                : $remainingForLine;
        }

        $this->syncSubmissionLineTotals($po);
    }

    /** Recompute shipment totals only from the currently selected PO lines. */
    public function updatedSubmissionLineQuantities(mixed $value, mixed $lineId): void
    {
        if (! $this->po_number) {
            return;
        }

        $po = VendorIdentity::apply(PurchaseOrder::query(), auth()->user())
            ->where('po_number', $this->po_number)
            ->with('lines')
            ->first();
        if (! $po || ! $po->lines->contains('id', (int) $lineId)) {
            return;
        }

        $this->syncSubmissionLineTotals($po);
    }

    private function syncSubmissionLineTotals(PurchaseOrder $po): void
    {
        $lines = $po->lines->keyBy('id');
        $totalQty = 0.0;
        $products = [];

        foreach ($this->submissionLineQuantities as $lineId => $quantity) {
            $line = $lines->get((int) $lineId);
            if (! $line) {
                continue;
            }

            $qty = max(0, (float) $quantity);
            $this->submissionLineQuantities[$lineId] = $qty;
            $lineQty = (float) $line->quantity;
            $totalQty += $qty;
            if ($qty > 0) {
                $products[] = (string) $line->product;
            }
        }

        $allocations = collect($this->submissionLineQuantities)
            ->mapWithKeys(fn ($qty, $lineId) => [(int) $lineId => (float) $qty])
            ->all();
        $totalAmount = app(PurchaseOrderAmountCalculator::class)->amounts($po, $allocations)['total'];

        $this->material = implode(' + ', array_unique($products));
        $this->invoice_qty = $totalQty > 0 ? (int) $totalQty : null;
        $this->invoice_amount = round($totalAmount, 2);
    }

    /** @return array{0: array<int, float>, 1: float} */
    private function billedLineQuantities(PurchaseOrder $po, $submissions): array
    {
        $known = [];
        $unallocated = 0.0;
        $lineIds = $po->lines->pluck('id')->all();

        foreach ($submissions as $submission) {
            $lineQuantities = is_array($submission->line_quantities) ? $submission->line_quantities : [];
            $validLineQuantities = false;
            foreach ($lineQuantities as $lineId => $quantity) {
                if (in_array((int) $lineId, $lineIds, true) && (float) $quantity > 0) {
                    $known[(int) $lineId] = ($known[(int) $lineId] ?? 0) + (float) $quantity;
                    $validLineQuantities = true;
                }
            }

            if (! $validLineQuantities && $po->lines->count() === 1) {
                $lineId = (int) $po->lines->first()->id;
                $known[$lineId] = ($known[$lineId] ?? 0) + (float) $submission->invoice_qty;
            } elseif (! $validLineQuantities) {
                $unallocated += (float) $submission->invoice_qty;
            }
        }

        return [$known, $unallocated];
    }

    public function submit(): void
    {
        $this->resetErrorBag('po_number');
        if (trim($this->po_number) === '') {
            $this->addError('po_number', 'Select an accepted PO before continuing.');

            return;
        }

        $po = VendorIdentity::apply(PurchaseOrder::with('lines'), auth()->user())
            ->where('po_number', $this->po_number)
            ->first();
        if (! $po) {
            $this->addError('po_number', 'The selected PO is not available to this vendor. Choose one of your accepted POs.');

            return;
        }
        $this->syncSubmissionLineTotals($po);
        $this->validate();

        // Confirmed live: the success banner claimed "Documents validated"
        // unconditionally, even with zero files attached (all three uploads
        // are individually nullable, so nothing stopped a fully document-free
        // submission from going through as if verified).
        if (! $this->invoice_file && ! $this->eway_bill_file && ! $this->lr_pod_file) {
            $this->addError('invoice_file', 'Attach at least one document (Invoice, E-way Bill, or LR/POD) before submitting.');

            return;
        }

        $vendorName = $po->vendor_name;
        $ack = VendorIdentity::apply(PurchaseOrderAcknowledgement::query(), auth()->user())
            ->where('po_number', $po->po_number)
            ->where('vendor_name', $vendorName)
            ->where('accepted', true)
            ->first();

        abort_unless($ack, 403);

        if ($this->invoice_number && VendorIdentity::apply(VendorSubmission::query(), auth()->user())->where('vendor_name', $vendorName)->where('invoice_number', $this->invoice_number)->exists()) {
            $this->addError('invoice_number', 'This invoice number is already submitted for this vendor.');

            return;
        }

        $orderedQty = (float) $po->lines->sum('quantity');
        $previousSubmissions = VendorIdentity::apply(VendorSubmission::query(), auth()->user())
            ->where('vendor_name', $vendorName)
            ->where('po_number', $po->po_number)
            ->get();
        [$billedByLine, $legacyUnallocatedQty] = $this->billedLineQuantities($po, $previousSubmissions);
        foreach ($this->submissionLineQuantities as $lineId => $quantity) {
            $line = $po->lines->firstWhere('id', (int) $lineId);
            if (! $line || (float) $quantity < 0 || (float) $quantity > max((float) $line->quantity - (float) ($billedByLine[$lineId] ?? 0), 0)) {
                $this->addError("submissionLineQuantities.{$lineId}", 'The invoice quantity cannot exceed this PO line\'s unbilled quantity.');

                return;
            }
        }

        $alreadyBilledQty = (float) $previousSubmissions->sum('invoice_qty');
        if ($orderedQty > 0 && $alreadyBilledQty + (float) $this->invoice_qty > $orderedQty) {
            $this->addError('invoice_qty', 'This dispatch quantity exceeds the remaining quantity for this PO.');

            return;
        }

        if ($legacyUnallocatedQty > 0 && $po->lines->count() > 1) {
            session()->flash('info', 'Prior invoice quantities for this multi-SKU PO do not have a recorded SKU split. This dispatch is captured per SKU; confirm each quantity against the vendor invoice.');
        }

        $invoicePath = $this->invoice_file?->store('documents');
        $ewayPath = $this->eway_bill_file?->store('documents');
        $lrPath = $this->lr_pod_file?->store('documents');

        $submission = VendorSubmission::create([
            'po_number' => $this->po_number,
            'vendor_name' => $vendorName,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date ?: null,
            // Vendor invoice due date is not part of the dispatch submission.
            // The PO's own due date remains the authoritative TAT date.
            'invoice_due_date' => null,
            'invoice_qty' => $this->invoice_qty,
            'invoice_amount' => $this->invoice_amount,
            'material' => $this->material,
            'line_quantities' => collect($this->submissionLineQuantities)
                ->map(fn ($quantity) => (float) $quantity)
                ->filter(fn ($quantity) => $quantity > 0)
                ->all(),
            'expected_arrival_at' => $this->expected_arrival_at ?: null,
            'vehicle_number' => $this->vehicle_number ?: null,
            'has_invoice' => (bool) $this->invoice_file,
            'invoice_document_path' => $invoicePath,
            'has_eway_bill' => (bool) $this->eway_bill_file,
            'eway_bill_document_path' => $ewayPath,
            'has_lr_pod' => (bool) $this->lr_pod_file,
            'lr_pod_document_path' => $lrPath,
            'status' => 'submitted',
            'production_started_at' => $ack->production_started_at ?? $ack->acknowledged_at,
            'dispatched_at' => now(),
            'tat_due_at' => $po->due_date?->copy()->endOfDay(),
        ]);

        $docCount = collect([$invoicePath, $ewayPath, $lrPath])->filter()->count();

        AuditLogger::log('Vendor submission created', "{$submission->po_number} · {$submission->invoice_number}", $submission);

        $this->reset(['po_number', 'invoice_number', 'invoice_date', 'invoice_qty', 'invoice_amount', 'material', 'expected_arrival_at', 'vehicle_number', 'invoice_file', 'eway_bill_file', 'lr_pod_file', 'dispatch_confirmation', 'adding', 'submissionLineQuantities', 'selectedPoLines', 'selectedPoSummary']);

        session()->flash('success', "Submission recorded with {$docCount} document(s) attached.");
    }

    public function acknowledgeIssue(int $id): void
    {
        $issue = ValidationIssue::whereHas('gateEntry', fn ($q) => VendorIdentity::apply($q, auth()->user()))->findOrFail($id);
        $issue->update(['status' => 'resolved']);

        AuditLogger::log('Issue resolved', (string) $issue->id, $issue);
    }

    public function with(): array
    {
        $user = auth()->user();
        $vendorName = $user->name;
        $submissions = VendorIdentity::apply(VendorSubmission::query(), $user)->orderBy('created_at', 'desc')->get();

        // Confirmed live: the vendor had to type the PO number by hand,
        // free-text, even though every real PO they could legitimately
        // submit against is already known -- released to them and accepted.
        $acceptedPoNumbers = VendorIdentity::apply(PurchaseOrderAcknowledgement::query(), $user)
            ->where('accepted', true)
            ->pluck('po_number');

        $acceptedOrders = VendorIdentity::apply(PurchaseOrder::query(), $user)
            ->whereNotNull('released_at')
            ->whereIn('po_number', $acceptedPoNumbers)
            ->with('lines')
            ->orderByDesc('released_at')
            ->get();
        $acceptedLive = app(PurchaseOrderProgressService::class)->summarize($acceptedOrders, $vendorName);

        $poNumbers = $submissions->pluck('po_number')->filter()->unique();
        $purchaseOrders = PurchaseOrder::whereIn('po_number', $poNumbers)->with('lines')->get()->keyBy('po_number');

        // Same fix as vendor/dashboard.blade.php: fulfilled used to be
        // purely the invoiced quantity, so a PO showed "100/100 fulfilled"
        // even after QC only accepted 95 of it.
        $acceptedByPo = QcResult::whereHas('gateEntry', fn ($q) => VendorIdentity::apply($q, $user)->whereNotNull('po_number'))
            ->with('gateEntry:id,po_number')
            ->get()
            ->groupBy(fn ($qc) => $qc->gateEntry?->po_number)
            ->map(fn ($group) => $group->sum('accepted_qty'));

        $fulfillment = $poNumbers->map(function ($po) use ($submissions, $purchaseOrders, $acceptedByPo) {
            $ordered = (float) ($purchaseOrders->get($po)?->primaryLine()?->quantity ?? 0);
            $fulfilled = $acceptedByPo->has($po) ? (float) $acceptedByPo->get($po) : (float) $submissions->where('po_number', $po)->sum('invoice_qty');

            return [
                'po_number' => $po,
                'ordered' => $ordered,
                'fulfilled' => $fulfilled,
                'remaining' => max($ordered - $fulfilled, 0),
                'over' => $fulfilled > $ordered,
                'invoice_count' => $submissions->where('po_number', $po)->count(),
                'pct' => $ordered > 0 ? min(100, (int) round($fulfilled / $ordered * 100)) : 0,
            ];
        })->filter(fn ($f) => $f['ordered'] > 0)->values();

        $openIssues = ValidationIssue::whereHas('gateEntry', fn ($q) => VendorIdentity::apply($q, $user))
            ->where('status', 'open')
            ->with('gateEntry')
            ->get()
            ->groupBy(fn ($issue) => $issue->gateEntry->po_number);

        $billShareUrls = $submissions->mapWithKeys(fn (VendorSubmission $submission) => [
            $submission->id => URL::temporarySignedRoute(
                'vendor.bill.share',
                now()->addDay(),
                ['submission' => $submission->id],
            ),
        ]);

        // Confirmed live: this badge is set to "submitted" at upload time
        // and never changes again -- a submission whose gate entry has long
        // since closed still just says "Submitted" forever, with no way to
        // tell it's actually done.
        $gateStatusByPo = VendorIdentity::apply(GateEntry::query(), $user)
            ->whereIn('po_number', $poNumbers)
            ->orderByDesc('created_at')
            ->get(['po_number', 'status'])
            ->unique('po_number')
            ->keyBy('po_number');

        return [
            'submissions' => $submissions,
            'fulfillment' => $fulfillment,
            'openIssuesByPo' => $openIssues,
            'gateStatusByPo' => $gateStatusByPo,
            'acceptedPoNumbers' => $acceptedPoNumbers,
            'acceptedOrders' => $acceptedOrders,
            'acceptedLive' => $acceptedLive,
            'billShareUrls' => $billShareUrls,
        ];
    }
}; ?>

<div class="space-y-5">
    @if (session('info'))
        <div role="status" class="rounded-xl border px-4 py-3 text-sm" style="background: var(--status-warning-bg); border-color: var(--status-warning); color: var(--text-primary);">{{ session('info') }}</div>
    @endif
    @if (session('success'))
        <div role="status" class="rounded-xl border px-4 py-3 text-sm" style="background: var(--status-good-bg); border-color: var(--status-good); color: var(--status-good);">{{ session('success') }}</div>
    @endif
    <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Vendor Module</div>
                <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">My Submissions</h1>
                <p class="text-sm mt-1" style="color: var(--text-secondary);">History of all your POs and dispatches.</p>
            </div>
            <button
                wire:click="$toggle('adding')"
                class="inline-flex items-center justify-center gap-1.5 rounded-xl px-3.5 py-2.5 text-sm font-semibold border shrink-0"
                style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);"
            >
                {{ $adding ? 'Cancel' : 'Add New Submission' }}
            </button>
        </div>
    </section>

    @if (session()->has('info'))
        <div class="p-3 rounded-lg text-sm" style="background: var(--brand-bg); color: var(--brand);">{{ session('info') }}</div>
    @endif

    @if (session()->has('success'))
        <div class="p-3 rounded-lg text-sm" style="background: var(--status-good-bg); color: var(--status-good);">{{ session('success') }}</div>
    @endif

    <section class="rounded-2xl border p-3" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Vendor submission views">
            <button type="button" wire:click="$set('tab', 'accepted')" class="inline-flex min-h-11 items-center rounded-xl px-4 py-2.5 text-sm font-bold" style="background: {{ $tab === 'accepted' ? 'var(--status-good-bg)' : 'var(--surface-2)' }}; color: {{ $tab === 'accepted' ? 'var(--status-good)' : 'var(--text-secondary)' }};">Accepted POs <span class="ml-2 rounded-full px-2 py-0.5 text-xs" style="background: var(--surface-1);">{{ $acceptedOrders->count() }}</span></button>
            <button type="button" wire:click="$set('tab', 'history')" class="inline-flex min-h-11 items-center rounded-xl px-4 py-2.5 text-sm font-semibold" style="background: {{ $tab === 'history' ? 'var(--brand-bg)' : 'var(--surface-2)' }}; color: {{ $tab === 'history' ? 'var(--brand)' : 'var(--text-secondary)' }};">Submission History <span class="ml-2 rounded-full px-2 py-0.5 text-xs" style="background: var(--surface-1);">{{ $submissions->count() }}</span></button>
            <button type="button" wire:click="startSubmission('')" class="inline-flex min-h-11 items-center rounded-xl px-4 py-2.5 text-sm font-semibold ml-auto" style="background: var(--brand); color: white;">+ New dispatch submission</button>
        </div>
    </section>

    @if ($tab === 'accepted' && ! $adding)
        <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--status-good);">
            <div class="flex items-start justify-between gap-3 mb-4">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--status-good);">Ready queue</div>
                    <h2 class="text-lg font-semibold mt-1" style="color: var(--text-primary);">Accepted POs</h2>
                    <p class="text-sm mt-1" style="color: var(--text-secondary);">These POs are accepted and production has started. Submit invoice and dispatch documents from the action on each card.</p>
                </div>
                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold" style="background: var(--status-good-bg); color: var(--status-good);">{{ $acceptedOrders->count() }} accepted</span>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                @forelse ($acceptedOrders as $acceptedOrder)
                    @php($live = $acceptedLive->get($acceptedOrder->po_number))
                    @php($latest = $submissions->where('po_number', $acceptedOrder->po_number)->first())
                    <article class="rounded-xl border p-4" style="border-color: var(--status-good); background: var(--surface-2);">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <strong class="text-sm break-all" style="color: var(--text-primary);">{{ $acceptedOrder->po_number }}</strong>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide" style="background: var(--status-good-bg); color: var(--status-good);">Accepted</span>
                                </div>
                                <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $acceptedOrder->subject ?: 'Purchase order' }} · {{ $acceptedOrder->lines->count() }} SKU(s)</div>
                            </div>
                            <span class="text-xs font-semibold text-right shrink-0" style="color: var(--status-good);">{{ $live['stage'] ?? 'Accepted' }}</span>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs" style="color: var(--text-secondary);">
                            <div><span style="color: var(--text-muted);">Ordered</span><br><strong style="color: var(--text-primary);">{{ number_format((float) $acceptedOrder->lines->sum('quantity'), 0) }}</strong></div>
                            <div><span style="color: var(--text-muted);">Remaining</span><br><strong style="color: var(--text-primary);">{{ number_format((float) ($live['remaining_qty'] ?? $acceptedOrder->lines->sum('quantity')), 0) }}</strong></div>
                        </div>
                        <div class="rounded-lg border px-3 py-2 mt-3 text-xs" style="border-color: var(--border); background: var(--surface-1); color: var(--text-secondary);">
                            <strong style="color: var(--text-primary);">Next:</strong> {{ $live['next_action']['label'] ?? 'Upload invoice and dispatch documents' }}
                        </div>
                        <div class="flex flex-wrap gap-3 mt-3 text-xs font-semibold">
                            @if (! $latest || ! $latest->dispatched_at)
                                <button type="button" wire:click="startSubmission('{{ $acceptedOrder->po_number }}')" class="inline-flex min-h-11 items-center rounded-xl px-3 py-2" style="background: var(--status-good); color: white;">Upload invoice / dispatch →</button>
                            @else
                                <a href="{{ route('vendor.submission-activity', $latest->id) }}" wire:navigate style="color: var(--brand);">Open dispatch details →</a>
                            @endif
                            <a href="{{ route('vendor.purchase-orders') }}#vendor-po-{{ \Illuminate\Support\Str::slug($acceptedOrder->po_number) }}" wire:navigate style="color: var(--brand);">View PO</a>
                                <a href="{{ route('vendor.po.pdf', $acceptedOrder->po_number) }}" target="_blank" rel="noopener" style="color: var(--brand);">PO PDF + QR</a>
                                <a href="{{ route('vendor.po.excel', $acceptedOrder->po_number) }}" style="color:var(--brand);">PO Excel</a>
                        </div>
                    </article>
                @empty
                    <div class="lg:col-span-2 rounded-xl border p-6 text-center text-sm" style="border-color: var(--border); color: var(--text-muted);">No accepted PO yet. Accept a released PO from Purchase Orders and it will appear here immediately.</div>
                @endforelse
            </div>
        </section>
    @endif

    @if ($adding)
        <form wire:submit="submit" class="rounded-2xl border p-6 space-y-6" style="background: var(--surface-3); border-color: var(--border);">
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border p-3" style="border-color: var(--brand); background: var(--brand-bg);">
                <div class="text-sm" style="color: var(--text-primary);"><strong>Dispatch submission</strong><div class="text-xs mt-0.5" style="color: var(--text-secondary);">Select an accepted PO, verify the auto-filled details, then upload at least one dispatch document.</div></div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="clearSubmissionForm" class="inline-flex min-h-11 items-center rounded-xl border px-3 py-2 text-xs font-bold" style="border-color: var(--border); color: var(--text-primary);">Clear form</button>
                    <button type="button" wire:click="$set('adding', false)" class="inline-flex min-h-11 items-center rounded-xl border px-3 py-2 text-xs font-semibold" style="border-color: var(--border); color: var(--text-secondary);">Back to accepted POs</button>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">PO Number <span style="color: var(--status-critical);">*</span></label>
                    <select wire:model.live="po_number" class="w-full rounded-xl border px-3 py-2.5 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                        <option value="">Select a PO you've accepted…</option>
                        @foreach ($acceptedPoNumbers as $po)
                            <option value="{{ $po }}">{{ $po }}</option>
                        @endforeach
                    </select>
                    @if ($acceptedPoNumbers->isEmpty())
                        <p class="text-xs mt-1" style="color: var(--text-muted);">No accepted POs yet — accept one on <a href="{{ route('vendor.purchase-orders') }}" wire:navigate style="color: var(--brand);">Purchase Orders</a> first.</p>
                    @endif
                    @error('po_number') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                    @if ($selectedPoLines)
                        <div class="rounded-xl border p-3 mt-2 text-xs" style="border-color: var(--brand); background: var(--brand-bg); color: var(--text-secondary);">
                            <div class="font-semibold mb-1" style="color: var(--text-primary);">PO details fetched automatically</div>
                            <div class="grid grid-cols-2 gap-x-3 gap-y-1 mb-2">
                                <div>Vendor: <strong style="color: var(--text-primary);">{{ $selectedPoSummary['vendor'] ?? '—' }}</strong></div>
                                <div>PO status: <strong style="color: var(--status-good);">{{ $selectedPoSummary['status'] ?? '—' }}</strong></div>
                                <div>PO date: <strong style="color: var(--text-primary);">{{ $selectedPoSummary['po_date'] ?? '—' }}</strong></div>
                                <div>Due date: <strong style="color: var(--text-primary);">{{ $selectedPoSummary['due_date'] ?? '—' }}</strong></div>
                                <div>Ordered: <strong style="color: var(--text-primary);">{{ number_format((float) ($selectedPoSummary['ordered_qty'] ?? 0), 0) }}</strong></div>
                                <div>Remaining: <strong style="color: var(--text-primary);">{{ number_format((float) ($selectedPoSummary['remaining_qty'] ?? 0), 0) }}</strong></div>
                            </div>
                            @foreach ($selectedPoLines as $line)
                                <div class="grid grid-cols-1 sm:grid-cols-[2fr_1fr_1fr] gap-2 items-center border-t py-2" style="border-color: var(--border);">
                                    <div>
                                        <div class="font-medium" style="color: var(--text-primary);">{{ $line['product'] }}</div>
                                        <div>Ordered {{ number_format($line['ordered'], 2) }} {{ $line['uom'] }} · Previously billed {{ number_format($line['billed'], 2) }} · Balance {{ number_format($line['remaining'], 2) }}</div>
                                    </div>
                                    <div>Unit price ₹{{ number_format($line['unit_price'], 2) }}</div>
                                    <label class="flex flex-col gap-1">
                                        <span class="font-medium" style="color: var(--text-primary);">This invoice ({{ $line['uom'] }})</span>
                                        <input type="number" min="0" max="{{ $line['remaining'] }}" step="0.001" wire:model.live.debounce.300ms="submissionLineQuantities.{{ $line['id'] }}" class="w-full rounded-lg border px-2.5 py-2 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                                        @error("submissionLineQuantities.{$line['id']}") <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                                    </label>
                                </div>
                            @endforeach
                            @if (($selectedPoSummary['legacy_unallocated_qty'] ?? 0) > 0)
                                <div role="note" class="rounded-lg border p-2 mt-2" style="border-color: var(--status-warning); color: var(--text-primary);">Earlier dispatches total {{ number_format($selectedPoSummary['legacy_unallocated_qty'], 2) }} units but have no saved SKU split. Check each new invoice line against its bill before entering quantities.</div>
                            @endif
                            <div class="mt-1">Invoice quantity and tax-inclusive amount below are calculated from these line entries.</div>
                        </div>
                    @endif
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">Material</label>
                    <input type="text" wire:model="material" class="w-full rounded-xl border px-3 py-2.5 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                    @error('material') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">Invoice Number</label>
                    <input type="text" wire:model="invoice_number" class="w-full rounded-xl border px-3 py-2.5 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                    @error('invoice_number') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">Invoice Quantity</label>
                    <input type="number" wire:model="invoice_qty" readonly class="w-full rounded-xl border px-3 py-2.5 text-sm" style="background: var(--surface-2); border-color: var(--border); color: var(--text-primary);">
                    @error('invoice_qty') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">Invoice Date</label>
                    <input type="date" wire:model="invoice_date" class="w-full rounded-xl border px-3 py-2.5 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                    @error('invoice_date') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">Invoice Amount (incl. tax)</label>
                    <input type="number" step="0.01" wire:model="invoice_amount" readonly class="w-full rounded-xl border px-3 py-2.5 text-sm" style="background: var(--surface-2); border-color: var(--border); color: var(--text-primary);">
                    @error('invoice_amount') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">Expected Arrival (ASN)</label>
                    <input type="datetime-local" wire:model="expected_arrival_at" class="w-full rounded-xl border px-3 py-2.5 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                    @error('expected_arrival_at') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">Vehicle Number</label>
                    <input type="text" wire:model="vehicle_number" class="w-full rounded-xl border px-3 py-2.5 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                    @error('vehicle_number') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="border-t pt-6" style="border-color: var(--border);">
                <h3 class="text-sm font-semibold mb-4" style="color: var(--text-primary);">Document Uploads</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <label class="block border-2 border-dashed rounded-xl p-4 text-center cursor-pointer" style="border-color: var(--border);">
                        <span class="block text-sm font-medium mb-2" style="color: var(--text-secondary);">Invoice File</span>
                        <input type="file" wire:model="invoice_file" class="text-xs w-full cursor-pointer" style="color: var(--text-primary);">
                        <div wire:loading wire:target="invoice_file" class="text-xs mt-2 font-medium" style="color: var(--brand);">Uploading...</div>
                        @error('invoice_file') <div class="text-xs mt-1" style="color: var(--status-critical);">{{ $message }}</div> @enderror
                    </label>

                    <label class="block border-2 border-dashed rounded-xl p-4 text-center cursor-pointer" style="border-color: var(--border);">
                        <span class="block text-sm font-medium mb-2" style="color: var(--text-secondary);">E-way Bill</span>
                        <input type="file" wire:model="eway_bill_file" class="text-xs w-full cursor-pointer" style="color: var(--text-primary);">
                        <div wire:loading wire:target="eway_bill_file" class="text-xs mt-2 font-medium" style="color: var(--brand);">Uploading...</div>
                        @error('eway_bill_file') <div class="text-xs mt-1" style="color: var(--status-critical);">{{ $message }}</div> @enderror
                    </label>

                    <label class="block border-2 border-dashed rounded-xl p-4 text-center cursor-pointer" style="border-color: var(--border);">
                        <span class="block text-sm font-medium mb-2" style="color: var(--text-secondary);">LR / POD</span>
                        <input type="file" wire:model="lr_pod_file" class="text-xs w-full cursor-pointer" style="color: var(--text-primary);">
                        <div wire:loading wire:target="lr_pod_file" class="text-xs mt-2 font-medium" style="color: var(--brand);">Uploading...</div>
                        @error('lr_pod_file') <div class="text-xs mt-1" style="color: var(--status-critical);">{{ $message }}</div> @enderror
                    </label>
                </div>
            </div>

            <div class="border-t pt-6" style="border-color: var(--border);">
                <label class="flex items-start gap-2">
                    <input type="checkbox" wire:model="dispatch_confirmation" class="mt-1 rounded border" style="border-color: var(--border);">
                    <span class="text-sm" style="color: var(--text-secondary);">
                        I confirm that production has been completed for this dispatch and the uploaded invoice / transport documents are accurate and final.
                    </span>
                </label>
                @error('dispatch_confirmation') <div class="text-xs mt-1" style="color: var(--status-critical);">{{ $message }}</div> @enderror
            </div>

            <div class="flex justify-end pt-4 border-t" style="border-color: var(--border);">
                <button type="submit" class="px-4 py-2.5 rounded-xl text-sm font-bold text-white" style="background: var(--brand);">Submit Details</button>
            </div>
        </form>
    @endif

    @if ($tab === 'history' && $fulfillment->isNotEmpty())
        <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Submission history</h2>
        <h2 class="text-sm font-semibold mb-2" style="color: var(--text-primary);">PO Fulfillment (multiple invoices per PO)</h2>
        <div class="space-y-2 mb-6">
            @foreach ($fulfillment as $f)
                <div class="rounded-lg border p-3" style="background: var(--surface-3); border-color: var(--border);">
                    <div class="flex items-center justify-between text-sm mb-1.5">
                        <span class="font-medium" style="color: var(--text-primary);">{{ $f['po_number'] }}</span>
                        <span style="color: {{ $f['over'] ? 'var(--status-critical)' : 'var(--text-secondary)' }};">{{ rtrim(rtrim(number_format($f['fulfilled'], 2), '0'), '.') }} / {{ rtrim(rtrim(number_format($f['ordered'], 2), '0'), '.') }} ({{ $f['invoice_count'] }} invoice(s)){{ $f['over'] ? ' — over-fulfilled' : '' }}</span>
                    </div>
                    <div class="w-full h-1.5 rounded-full overflow-hidden" style="background: var(--surface-2);">
                        <div class="h-full rounded-full" style="width: {{ $f['pct'] }}%; background: {{ $f['over'] ? 'var(--status-critical)' : 'var(--brand)' }};"></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($tab === 'history')
    <div class="rounded-lg border overflow-hidden" style="background: var(--surface-3); border-color: var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs" style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="px-4 py-2.5 font-medium">PO Number</th>
                    <th class="px-4 py-2.5 font-medium">Invoice #</th>
                    <th class="px-4 py-2.5 font-medium">Documents</th>
                    <th class="px-4 py-2.5 font-medium">Dock Slot</th>
                    <th class="px-4 py-2.5 font-medium">Status</th>
                    <th class="px-4 py-2.5 font-medium">Submitted On</th>
                    <th class="px-4 py-2.5 font-medium">Bill PDF</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($submissions as $sub)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2.5 font-medium">
                            <a href="{{ route('vendor.submission-activity', $sub->id) }}" wire:navigate style="color: var(--brand);">{{ $sub->po_number }}</a>
                        </td>
                        <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ $sub->invoice_number ?? '-' }}</td>
                        <td class="px-4 py-2.5" style="color: var(--text-secondary);">
                            <div class="flex gap-1 text-xs">
                                @if($sub->invoice_document_path)<a href="{{ route('vendor.submission.document', [$sub, 'invoice']) }}" class="px-1.5 py-0.5 rounded-full font-medium" style="background: var(--brand-bg); color: var(--brand);">INV</a>@endif
                                @if($sub->eway_bill_document_path)<a href="{{ route('vendor.submission.document', [$sub, 'eway']) }}" class="px-1.5 py-0.5 rounded-full font-medium" style="background: var(--brand-bg); color: var(--brand);">EWB</a>@endif
                                @if($sub->lr_pod_document_path)<a href="{{ route('vendor.submission.document', [$sub, 'lr']) }}" class="px-1.5 py-0.5 rounded-full font-medium" style="background: var(--surface-2); color: var(--text-secondary);">LR</a>@endif
                            </div>
                        </td>
                        <td class="px-4 py-2.5 text-xs" style="color: var(--text-secondary);">
                            @if ($sub->dock_number)
                                <div style="color: var(--status-good);">{{ $sub->dock_number }}</div>
                                <div>{{ $sub->dock_scheduled_at?->format('d M, H:i') }}</div>
                            @elseif ($sub->expected_arrival_at)
                                <div style="color: var(--status-warning);">Awaiting dock</div>
                                <div>ETA {{ $sub->expected_arrival_at->format('d M, H:i') }}</div>
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5">
                            <div style="color: {{ $sub->status == 'submitted' ? 'var(--status-good)' : 'var(--status-critical)' }};">
                                {{ ucfirst(str_replace('_', ' ', $sub->status)) }} · Dispatched
                            </div>
                            <div class="text-xs mt-0.5" style="color: {{ $sub->tat_due_at && $sub->dispatched_at?->gt($sub->tat_due_at) ? 'var(--status-critical)' : 'var(--status-good)' }};">
                                TAT {{ $sub->tat_due_at ? ($sub->dispatched_at?->lte($sub->tat_due_at) ? 'met' : 'missed') : 'not set' }}
                                @if ($sub->dispatched_at) · {{ $sub->dispatched_at->format('d M, H:i') }} @endif
                            </div>
                            @if ($gateStatusByPo->get($sub->po_number))
                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">Gate entry: {{ GateStatusLabels::label($gateStatusByPo->get($sub->po_number)->status) }}</div>
                            @endif
                            @if ($sub->status === 'correction_requested')
                                <div class="text-xs mt-1" style="color: var(--status-critical);">{{ $sub->note ?: 'Please re-upload your invoice or E-way bill.' }}</div>
                            @endif
                            @foreach ($openIssuesByPo->get($sub->po_number, collect()) as $issue)
                                <div class="mt-1.5 rounded-lg px-2 py-1.5" style="background: var(--status-warning-bg);">
                                    <div class="text-xs font-semibold" style="color: var(--status-warning);">{{ $issue->title }}</div>
                                    <div class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ $issue->description }}</div>
                                    <button wire:click="acknowledgeIssue({{ $issue->id }})" wire:confirm="Acknowledge and resolve this issue?" class="text-xs font-semibold hover:underline mt-1" style="color: var(--status-warning);">Acknowledge &amp; Resolve</button>
                                </div>
                            @endforeach
                        </td>
                        <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $sub->created_at->format('d M, Y H:i') }}</td>
                        <td class="px-4 py-2.5">
                            @php($billShareUrl = $billShareUrls->get($sub->id))
                            <div class="flex flex-wrap items-center gap-1.5 text-xs" x-data="{ shared: false, share() { const data = { title: 'TAN90 vendor bill {{ $sub->invoice_number ?: 'SUB-'.$sub->id }}', text: 'TAN90 bill for PO {{ $sub->po_number }}', url: @js($billShareUrl) }; if (navigator.share) { navigator.share(data).then(() => { this.shared = true; setTimeout(() => this.shared = false, 2500); }).catch(() => {}) } else if (navigator.clipboard) { navigator.clipboard.writeText(data.url).then(() => { this.shared = true; setTimeout(() => this.shared = false, 2500); }) } else { window.prompt('Copy this secure bill link', data.url) } } }">
                                <a href="{{ route('vendor.submission.pdf', $sub) }}" class="px-2 py-1 rounded-lg font-semibold" style="background: var(--brand-bg); color: var(--brand);">Download</a>
                                <a href="{{ route('vendor.submission.excel', $sub) }}" class="px-2 py-1 rounded-lg font-semibold border" style="border-color:var(--border);color:var(--brand);">Download Excel</a>
                                <button type="button" @click="share" class="px-2 py-1 rounded-lg font-semibold border" style="border-color: var(--border); color: var(--text-primary);" x-text="shared ? 'Link copied' : 'Share'"></button>
                            </div>
                            <div class="text-[10px] mt-1" style="color: var(--text-muted);">Secure link valid 24 hours</div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No submissions found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endif
</div>
