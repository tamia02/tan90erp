<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\QcResult;
use App\Models\ValidationIssue;
use App\Models\VendorSubmission;
use App\Services\AuditLogger;
use App\Support\GateStatusLabels;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public bool $adding = false;

    // Confirmed live: a vendor could type any string here and it was
    // accepted outright, no check against real PO data -- and since Guard's
    // Fetch checks VendorSubmission before PO Master/Zoho, a fabricated PO
    // number here would get trusted at the gate as if it were real.
    #[Validate('required|string|max:255|exists:purchase_orders,po_number')]
    public string $po_number = '';

    #[Validate('nullable|string|max:255')]
    public string $invoice_number = '';

    #[Validate('nullable|integer|min:1')]
    public ?int $invoice_qty = null;

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
    public bool $zoho_terms = false;

    /** Laravel's default error text otherwise reads "the po number field",
     * "the zoho terms field must be accepted" -- confirmed live as a real
     * point of confusion for a vendor filling this form. */
    protected function validationAttributes(): array
    {
        return [
            'po_number' => 'PO Number',
            'invoice_number' => 'Invoice Number',
            'invoice_qty' => 'Invoice Quantity',
            'material' => 'Material',
            'expected_arrival_at' => 'Expected Arrival',
            'vehicle_number' => 'Vehicle Number',
            'invoice_file' => 'Invoice File',
            'eway_bill_file' => 'E-way Bill',
            'lr_pod_file' => 'LR / POD',
            'zoho_terms' => 'Terms agreement',
        ];
    }

    public function submit(): void
    {
        $this->validate();

        // Confirmed live: the success banner claimed "Documents validated"
        // unconditionally, even with zero files attached (all three uploads
        // are individually nullable, so nothing stopped a fully document-free
        // submission from going through as if verified).
        if (! $this->invoice_file && ! $this->eway_bill_file && ! $this->lr_pod_file) {
            $this->addError('invoice_file', 'Attach at least one document (Invoice, E-way Bill, or LR/POD) before submitting.');

            return;
        }

        $submission = VendorSubmission::create([
            'po_number' => $this->po_number,
            'vendor_name' => auth()->user()->name,
            'invoice_number' => $this->invoice_number,
            'invoice_qty' => $this->invoice_qty,
            'material' => $this->material,
            'expected_arrival_at' => $this->expected_arrival_at ?: null,
            'vehicle_number' => $this->vehicle_number ?: null,
            'has_invoice' => (bool) $this->invoice_file,
            'has_eway_bill' => (bool) $this->eway_bill_file,
            'has_lr_pod' => (bool) $this->lr_pod_file,
            'status' => 'submitted',
        ]);

        $docCount = 0;
        if ($this->invoice_file) { $this->invoice_file->store('documents'); $docCount++; }
        if ($this->eway_bill_file) { $this->eway_bill_file->store('documents'); $docCount++; }
        if ($this->lr_pod_file) { $this->lr_pod_file->store('documents'); $docCount++; }

        AuditLogger::log('Vendor submission created', "{$submission->po_number} · {$submission->invoice_number}", $submission);

        $this->reset(['po_number', 'invoice_number', 'invoice_qty', 'material', 'expected_arrival_at', 'vehicle_number', 'invoice_file', 'eway_bill_file', 'lr_pod_file', 'zoho_terms', 'adding']);

        session()->flash('success', "Submission recorded with {$docCount} document(s) attached.");
    }

    public function acknowledgeIssue(int $id): void
    {
        $issue = ValidationIssue::whereHas('gateEntry', fn ($q) => $q->where('vendor_name', auth()->user()->name))->findOrFail($id);
        $issue->update(['status' => 'resolved']);

        AuditLogger::log('Issue resolved', (string) $issue->id, $issue);
    }

    public function with(): array
    {
        $vendorName = auth()->user()->name;
        $submissions = VendorSubmission::where('vendor_name', $vendorName)->orderBy('created_at', 'desc')->get();

        $poNumbers = $submissions->pluck('po_number')->filter()->unique();
        $purchaseOrders = PurchaseOrder::whereIn('po_number', $poNumbers)->with('lines')->get()->keyBy('po_number');

        // Same fix as vendor/dashboard.blade.php: fulfilled used to be
        // purely the invoiced quantity, so a PO showed "100/100 fulfilled"
        // even after QC only accepted 95 of it.
        $acceptedByPo = QcResult::whereHas('gateEntry', fn ($q) => $q->where('vendor_name', $vendorName)->whereNotNull('po_number'))
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
                'invoice_count' => $submissions->where('po_number', $po)->count(),
                'pct' => $ordered > 0 ? min(100, (int) round($fulfilled / $ordered * 100)) : 0,
            ];
        })->filter(fn ($f) => $f['ordered'] > 0)->values();

        $openIssues = ValidationIssue::whereHas('gateEntry', fn ($q) => $q->where('vendor_name', $vendorName))
            ->where('status', 'open')
            ->with('gateEntry')
            ->get()
            ->groupBy(fn ($issue) => $issue->gateEntry->po_number);

        // Confirmed live: this badge is set to "submitted" at upload time
        // and never changes again -- a submission whose gate entry has long
        // since closed still just says "Submitted" forever, with no way to
        // tell it's actually done.
        $gateStatusByPo = GateEntry::where('vendor_name', $vendorName)
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
        ];
    }
}; ?>

<div class="space-y-5">
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

    @if (session()->has('success'))
        <div class="p-3 rounded-lg text-sm" style="background: var(--status-good-bg); color: var(--status-good);">{{ session('success') }}</div>
    @endif

    @if ($adding)
        <form wire:submit="submit" class="rounded-2xl border p-6 space-y-6" style="background: var(--surface-3); border-color: var(--border);">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">PO Number <span style="color: var(--status-critical);">*</span></label>
                    <input type="text" wire:model="po_number" class="w-full rounded-xl border px-3 py-2.5 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                    @error('po_number') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
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
                    <input type="number" wire:model="invoice_qty" class="w-full rounded-xl border px-3 py-2.5 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                    @error('invoice_qty') <span class="text-xs" style="color: var(--status-critical);">{{ $message }}</span> @enderror
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
                    <input type="checkbox" wire:model="zoho_terms" class="mt-1 rounded border" style="border-color: var(--border);">
                    <span class="text-sm" style="color: var(--text-secondary);">
                        I agree to the <strong style="color: var(--text-primary);">Zoho Terms for Business Operations</strong> and confirm that the uploaded dispatch details and invoices are accurate and final.
                    </span>
                </label>
                @error('zoho_terms') <div class="text-xs mt-1" style="color: var(--status-critical);">{{ $message }}</div> @enderror
            </div>

            <div class="flex justify-end pt-4 border-t" style="border-color: var(--border);">
                <button type="submit" class="px-4 py-2.5 rounded-xl text-sm font-bold text-white" style="background: var(--brand);">Submit Details</button>
            </div>
        </form>
    @endif

    @if ($fulfillment->isNotEmpty())
        <h2 class="text-sm font-semibold mb-2" style="color: var(--text-primary);">PO Fulfillment (multiple invoices per PO)</h2>
        <div class="space-y-2 mb-6">
            @foreach ($fulfillment as $f)
                <div class="rounded-lg border p-3" style="background: var(--surface-3); border-color: var(--border);">
                    <div class="flex items-center justify-between text-sm mb-1.5">
                        <span class="font-medium" style="color: var(--text-primary);">{{ $f['po_number'] }}</span>
                        <span style="color: var(--text-secondary);">{{ rtrim(rtrim(number_format($f['fulfilled'], 2), '0'), '.') }} / {{ rtrim(rtrim(number_format($f['ordered'], 2), '0'), '.') }} ({{ $f['invoice_count'] }} invoice(s))</span>
                    </div>
                    <div class="w-full h-1.5 rounded-full overflow-hidden" style="background: var(--surface-2);">
                        <div class="h-full rounded-full" style="width: {{ $f['pct'] }}%; background: var(--brand);"></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

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
                                @if($sub->has_invoice)<span class="px-1.5 py-0.5 rounded-full font-medium" style="background: var(--brand-bg); color: var(--brand);">INV</span>@endif
                                @if($sub->has_eway_bill)<span class="px-1.5 py-0.5 rounded-full font-medium" style="background: var(--brand-bg); color: var(--brand);">EWB</span>@endif
                                @if($sub->has_lr_pod)<span class="px-1.5 py-0.5 rounded-full font-medium" style="background: var(--surface-2); color: var(--text-secondary);">LR</span>@endif
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
                                {{ ucfirst(str_replace('_', ' ', $sub->status)) }}
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
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No submissions found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
