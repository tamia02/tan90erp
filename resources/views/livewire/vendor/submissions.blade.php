<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use App\Models\PurchaseOrder;
use App\Models\ValidationIssue;
use App\Models\VendorSubmission;
use App\Services\AuditLogger;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public bool $adding = false;

    #[Validate('required|string|max:255')]
    public string $po_number = '';

    #[Validate('nullable|string|max:255')]
    public string $invoice_number = '';

    #[Validate('nullable|integer|min:1')]
    public ?int $invoice_qty = null;

    #[Validate('nullable|string|max:255')]
    public string $material = '';

    #[Validate('nullable|file|max:5120')]
    public $invoice_file;

    #[Validate('nullable|file|max:5120')]
    public $eway_bill_file;

    #[Validate('nullable|file|max:5120')]
    public $lr_pod_file;

    #[Validate('accepted')]
    public bool $zoho_terms = false;

    public function submit(): void
    {
        $this->validate();

        $submission = VendorSubmission::create([
            'po_number' => $this->po_number,
            'vendor_name' => auth()->user()->name,
            'invoice_number' => $this->invoice_number,
            'invoice_qty' => $this->invoice_qty,
            'material' => $this->material,
            'has_invoice' => (bool) $this->invoice_file,
            'has_eway_bill' => (bool) $this->eway_bill_file,
            'has_lr_pod' => (bool) $this->lr_pod_file,
            'status' => 'submitted',
        ]);

        if ($this->invoice_file) $this->invoice_file->store('documents');
        if ($this->eway_bill_file) $this->eway_bill_file->store('documents');
        if ($this->lr_pod_file) $this->lr_pod_file->store('documents');

        AuditLogger::log('Vendor submission created', "{$submission->po_number} · {$submission->invoice_number}");

        $this->reset(['po_number', 'invoice_number', 'invoice_qty', 'material', 'invoice_file', 'eway_bill_file', 'lr_pod_file', 'zoho_terms', 'adding']);

        session()->flash('success', 'Submission completed successfully! Documents validated.');
    }

    public function acknowledgeIssue(int $id): void
    {
        $issue = ValidationIssue::whereHas('gateEntry', fn ($q) => $q->where('vendor_name', auth()->user()->name))->findOrFail($id);
        $issue->update(['status' => 'resolved']);

        AuditLogger::log('Issue resolved', (string) $issue->id);
    }

    public function with(): array
    {
        $vendorName = auth()->user()->name;
        $submissions = VendorSubmission::where('vendor_name', $vendorName)->orderBy('created_at', 'desc')->get();

        $poNumbers = $submissions->pluck('po_number')->filter()->unique();
        $purchaseOrders = PurchaseOrder::whereIn('po_number', $poNumbers)->with('lines')->get()->keyBy('po_number');

        $fulfillment = $poNumbers->map(function ($po) use ($submissions, $purchaseOrders) {
            $ordered = (float) ($purchaseOrders->get($po)?->primaryLine()?->quantity ?? 0);
            $fulfilled = (float) $submissions->where('po_number', $po)->sum('invoice_qty');

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

        return [
            'submissions' => $submissions,
            'fulfillment' => $fulfillment,
            'openIssuesByPo' => $openIssues,
        ];
    }
}; ?>

<div class="max-w-4xl mx-auto">
    <div class="flex items-center justify-between mb-1">
        <h1 class="text-xl font-semibold" style="color: var(--text-primary);">My Submissions</h1>
        <button
            wire:click="$toggle('adding')"
            class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium border"
            style="background: var(--surface-1); color: var(--text-primary); border-color: var(--border);"
        >
            {{ $adding ? 'Cancel' : 'Add New Submission' }}
        </button>
    </div>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">History of all your POs and dispatches.</p>

    @if (session()->has('success'))
        <div class="mb-4 p-3 rounded text-sm text-green-800 bg-green-100">{{ session('success') }}</div>
    @endif

    @if ($adding)
        <form wire:submit="submit" class="p-6 rounded-lg border space-y-6 mb-6" style="background: var(--surface-3); border-color: var(--border);">
            <div class="grid grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">PO Number <span class="text-red-500">*</span></label>
                    <input type="text" wire:model="po_number" class="w-full rounded border px-3 py-2 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                    @error('po_number') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">Material</label>
                    <input type="text" wire:model="material" class="w-full rounded border px-3 py-2 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                    @error('material') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">Invoice Number</label>
                    <input type="text" wire:model="invoice_number" class="w-full rounded border px-3 py-2 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                    @error('invoice_number') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-primary);">Invoice Quantity</label>
                    <input type="number" wire:model="invoice_qty" class="w-full rounded border px-3 py-2 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);">
                    @error('invoice_qty') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="border-t pt-6" style="border-color: var(--border);">
                <h3 class="text-sm font-semibold mb-4" style="color: var(--text-primary);">Document Uploads</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <label class="block border-2 border-dashed rounded-lg p-4 text-center cursor-pointer" style="border-color: var(--border);">
                        <span class="block text-sm font-medium mb-2" style="color: var(--text-secondary);">Invoice File</span>
                        <input type="file" wire:model="invoice_file" class="text-xs w-full cursor-pointer" style="color: var(--text-primary);">
                        <div wire:loading wire:target="invoice_file" class="text-xs mt-2 font-medium" style="color: var(--primary);">Uploading...</div>
                        @error('invoice_file') <div class="text-xs text-red-500 mt-1">{{ $message }}</div> @enderror
                    </label>

                    <label class="block border-2 border-dashed rounded-lg p-4 text-center cursor-pointer" style="border-color: var(--border);">
                        <span class="block text-sm font-medium mb-2" style="color: var(--text-secondary);">E-way Bill</span>
                        <input type="file" wire:model="eway_bill_file" class="text-xs w-full cursor-pointer" style="color: var(--text-primary);">
                        <div wire:loading wire:target="eway_bill_file" class="text-xs mt-2 font-medium" style="color: var(--primary);">Uploading...</div>
                        @error('eway_bill_file') <div class="text-xs text-red-500 mt-1">{{ $message }}</div> @enderror
                    </label>

                    <label class="block border-2 border-dashed rounded-lg p-4 text-center cursor-pointer" style="border-color: var(--border);">
                        <span class="block text-sm font-medium mb-2" style="color: var(--text-secondary);">LR / POD</span>
                        <input type="file" wire:model="lr_pod_file" class="text-xs w-full cursor-pointer" style="color: var(--text-primary);">
                        <div wire:loading wire:target="lr_pod_file" class="text-xs mt-2 font-medium" style="color: var(--primary);">Uploading...</div>
                        @error('lr_pod_file') <div class="text-xs text-red-500 mt-1">{{ $message }}</div> @enderror
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
                @error('zoho_terms') <div class="text-xs text-red-500 mt-1">{{ $message }}</div> @enderror
            </div>

            <div class="flex justify-end pt-4 border-t" style="border-color: var(--border);">
                <button type="submit" class="px-4 py-2 rounded text-sm font-medium text-white" style="background: var(--primary);">Submit Details</button>
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
                    <th class="px-4 py-2.5 font-medium">Status</th>
                    <th class="px-4 py-2.5 font-medium">Submitted On</th>
                    <th class="px-4 py-2.5 font-medium"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($submissions as $sub)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $sub->po_number }}</td>
                        <td class="px-4 py-2.5" style="color: var(--text-primary);">{{ $sub->invoice_number ?? '-' }}</td>
                        <td class="px-4 py-2.5" style="color: var(--text-secondary);">
                            <div class="flex gap-1 text-xs">
                                @if($sub->has_invoice)<span class="px-1.5 py-0.5 rounded bg-blue-100 text-blue-800">INV</span>@endif
                                @if($sub->has_eway_bill)<span class="px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-800">EWB</span>@endif
                                @if($sub->has_lr_pod)<span class="px-1.5 py-0.5 rounded bg-gray-100 text-gray-800">LR</span>@endif
                            </div>
                        </td>
                        <td class="px-4 py-2.5">
                            <div style="color: {{ $sub->status == 'submitted' ? 'var(--status-good)' : 'var(--status-critical)' }};">
                                {{ ucfirst(str_replace('_', ' ', $sub->status)) }}
                            </div>
                            @if ($sub->status === 'correction_requested')
                                <div class="text-xs mt-1" style="color: var(--status-critical);">{{ $sub->note ?: 'Please re-upload your invoice or E-way bill.' }}</div>
                            @endif
                            @foreach ($openIssuesByPo->get($sub->po_number, collect()) as $issue)
                                <div class="mt-1.5 rounded border border-orange-200 bg-orange-50 px-2 py-1.5">
                                    <div class="text-xs font-medium text-orange-800">{{ $issue->title }}</div>
                                    <div class="text-xs text-orange-700 mt-0.5">{{ $issue->description }}</div>
                                    <button wire:click="acknowledgeIssue({{ $issue->id }})" wire:confirm="Acknowledge and resolve this issue?" class="text-xs font-medium text-orange-600 hover:underline mt-1">Acknowledge &amp; Resolve</button>
                                </div>
                            @endforeach
                        </td>
                        <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $sub->created_at->format('d M, Y H:i') }}</td>
                        <td class="px-4 py-2.5 text-right">
                            <a href="{{ route('vendor.submission-activity', $sub->id) }}" wire:navigate class="text-xs font-medium" style="color: var(--brand);">View Activity</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No submissions found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
