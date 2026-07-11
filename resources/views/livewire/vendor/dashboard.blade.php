<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use App\Models\AuditLogEntry;
use App\Models\FinanceRecord;
use App\Models\PurchaseOrder;
use App\Models\QcResult;
use App\Models\Rfq;
use App\Models\VendorSubmission;
use App\Services\AuditLogger;

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

    public function initiatePurchaseReturn(int $qcResultId): void
    {
        $vendorName = auth()->user()->name;

        $result = QcResult::whereHas('gateEntry', fn ($q) => $q->where('vendor_name', $vendorName))
            ->where('return_status', 'pending')
            ->findOrFail($qcResultId);

        $result->update(['return_status' => 'initiated', 'return_initiated_at' => now()]);

        AuditLogger::log('Purchase return initiated', "{$result->gateEntry?->gate_no} · {$vendorName} · rejected {$result->rejected_qty}", $result);
    }

    public function with(): array
    {
        $vendorName = auth()->user()->name;

        $activity = AuditLogEntry::orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->filter(fn ($row) => collect(self::ACTIVITY_KEYWORDS)->contains(
                fn ($keyword) => str_contains($row->action, $keyword) || str_contains((string) $row->detail, $keyword)
            ))
            ->values();
        $allSubmissions = VendorSubmission::where('vendor_name', $vendorName)->get();
        $poNumbers = $allSubmissions->pluck('po_number')->filter()->unique();
        $purchaseOrders = PurchaseOrder::whereIn('po_number', $poNumbers)->with('lines')->get()->keyBy('po_number');

        $fulfillment = $poNumbers->map(function ($po) use ($allSubmissions, $purchaseOrders) {
            $ordered = (float) ($purchaseOrders->get($po)?->primaryLine()?->quantity ?? 0);
            $fulfilled = (float) $allSubmissions->where('po_number', $po)->sum('invoice_qty');

            return [
                'po_number' => $po,
                'ordered' => $ordered,
                'fulfilled' => $fulfilled,
                'remaining' => max($ordered - $fulfilled, 0),
                'invoice_count' => $allSubmissions->where('po_number', $po)->count(),
                'pct' => $ordered > 0 ? min(100, (int) round($fulfilled / $ordered * 100)) : 0,
            ];
        })->filter(fn ($f) => $f['ordered'] > 0)->values();

        $allFinanceRecords = FinanceRecord::where('vendor_name', $vendorName)->with('gateEntry')->orderByDesc('created_at')->get();
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

        $analyticsFrom = $this->analyticsFrom ? \Illuminate\Support\Carbon::parse($this->analyticsFrom)->startOfDay() : null;
        $analyticsTo = $this->analyticsTo ? \Illuminate\Support\Carbon::parse($this->analyticsTo)->endOfDay() : null;

        $filteredFinance = $allFinanceRecords->filter(
            fn ($fr) => (! $analyticsFrom || $fr->created_at->gte($analyticsFrom))
                && (! $analyticsTo || $fr->created_at->lte($analyticsTo))
        );

        return [
            'submissions' => $allSubmissions->sortByDesc('created_at')->take(5)->values(),
            'fulfillment' => $fulfillment,
            'openPos' => $fulfillment->where('remaining', '>', 0)->values(),
            'closedPos' => $fulfillment->where('remaining', '<=', 0)->values(),
            'financialGroups' => $financialGroups,
            'isAdvanced' => auth()->user()->isAdvancedVendor(),
            'activityTotal' => $activity->count(),
            'recentActivity' => $this->showAllActivity ? $activity : $activity->take(5),
            'rfqs' => Rfq::where('vendor_name', $vendorName)->orderByDesc('created_at')->take(5)->get(),
            'pendingReturns' => QcResult::whereHas('gateEntry', fn ($q) => $q->where('vendor_name', $vendorName))
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

<div class="max-w-4xl mx-auto">
    <h1 class="text-xl font-semibold mb-1" style="color: var(--text-primary);">Vendor Dashboard</h1>
    <p class="text-sm mb-4" style="color: var(--text-secondary);">Overview of your activities.</p>

    <div class="grid grid-cols-2 gap-4 mb-6">
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-sm" style="color: var(--text-secondary);">Total Submissions</div>
            <div class="text-2xl font-semibold" style="color: var(--text-primary);">{{ \App\Models\VendorSubmission::where('vendor_name', auth()->user()->name)->count() }}</div>
        </div>
        <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-sm" style="color: var(--text-secondary);">Issues Pending</div>
            <div class="text-2xl font-semibold" style="color: var(--status-critical);">{{ \App\Models\VendorSubmission::where('vendor_name', auth()->user()->name)->where('status', 'correction_requested')->count() }}</div>
        </div>
    </div>

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
                    <th class="px-4 py-2.5 font-medium"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($submissions as $sub)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-2.5 font-medium" style="color: var(--text-primary);">{{ $sub->po_number }}</td>
                        <td class="px-4 py-2.5" style="color: {{ $sub->status == 'submitted' ? 'var(--status-good)' : 'var(--status-critical)' }};">{{ ucfirst(str_replace('_', ' ', $sub->status)) }}</td>
                        <td class="px-4 py-2.5" style="color: var(--text-secondary);">{{ $sub->created_at->format('d M, Y') }}</td>
                        <td class="px-4 py-2.5 text-right">
                            <a href="{{ route('vendor.submission-activity', $sub->id) }}" wire:navigate class="text-xs font-medium" style="color: var(--brand);">View Activity</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-sm" style="color: var(--text-muted);">No submissions found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">PO Fulfillment</h2>
    <p class="text-xs mb-3" style="color: var(--text-secondary);">Purchase orders fulfilled across multiple invoices/dispatches, segregated by open vs closed financial status.</p>

    <h3 class="text-sm font-semibold mb-2" style="color: var(--status-warning);">Open ({{ $openPos->count() }})</h3>
    <div class="space-y-3 mb-4">
        @forelse ($openPos as $f)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-medium text-sm" style="color: var(--text-primary);">{{ $f['po_number'] }}</span>
                    <span class="text-xs" style="color: var(--text-muted);">{{ $f['invoice_count'] }} invoice(s)</span>
                </div>
                <div class="w-full h-2 rounded-full overflow-hidden" style="background: var(--surface-2);">
                    <div class="h-full rounded-full" style="width: {{ $f['pct'] }}%; background: var(--brand);"></div>
                </div>
                <div class="flex items-center justify-between mt-1.5 text-xs" style="color: var(--text-secondary);">
                    <span>{{ rtrim(rtrim(number_format($f['fulfilled'], 2), '0'), '.') }} / {{ rtrim(rtrim(number_format($f['ordered'], 2), '0'), '.') }} fulfilled</span>
                    <span>{{ $f['pct'] }}%</span>
                </div>
            </div>
        @empty
            <p class="text-sm py-4 text-center rounded-lg border" style="color: var(--text-muted); border-color: var(--border); background: var(--surface-3);">No open POs.</p>
        @endforelse
    </div>

    <h3 class="text-sm font-semibold mb-2" style="color: var(--status-good);">Closed ({{ $closedPos->count() }})</h3>
    <div class="space-y-3 mb-6">
        @forelse ($closedPos as $f)
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-medium text-sm" style="color: var(--text-primary);">{{ $f['po_number'] }}</span>
                    <span class="text-xs" style="color: var(--text-muted);">{{ $f['invoice_count'] }} invoice(s)</span>
                </div>
                <div class="w-full h-2 rounded-full overflow-hidden" style="background: var(--surface-2);">
                    <div class="h-full rounded-full" style="width: {{ $f['pct'] }}%; background: var(--status-good);"></div>
                </div>
                <div class="flex items-center justify-between mt-1.5 text-xs" style="color: var(--text-secondary);">
                    <span>{{ rtrim(rtrim(number_format($f['fulfilled'], 2), '0'), '.') }} / {{ rtrim(rtrim(number_format($f['ordered'], 2), '0'), '.') }} fulfilled</span>
                    <span>{{ $f['pct'] }}%</span>
                </div>
            </div>
        @empty
            <p class="text-sm py-4 text-center rounded-lg border" style="color: var(--text-muted); border-color: var(--border); background: var(--surface-3);">No closed POs yet.</p>
        @endforelse
    </div>

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
            <div class="p-2 rounded text-xs mb-3 text-green-800 bg-green-100">{{ session('rfqSuccess') }}</div>
        @endif
        <form wire:submit="submitRfq" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">SKU/Material</span>
                <input wire:model="rfqSku" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                @error('rfqSku') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium" style="color: var(--text-primary);">Quantity</span>
                <input wire:model="rfqQuantity" type="number" min="1" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                @error('rfqQuantity') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1.5 text-sm sm:col-span-2">
                <span class="font-medium" style="color: var(--text-primary);">Notes (optional)</span>
                <input wire:model="rfqNotes" class="rounded-lg border px-3 py-2 text-sm" style="border-color: var(--border);" />
                @error('rfqNotes') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
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
                <a href="{{ route('activity.detail', $row) }}" wire:navigate class="py-3 flex items-center justify-between gap-3 -mx-2 px-2 rounded-lg hover:bg-black/5">
                    <div class="min-w-0">
                        <div class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ $row->action }}</div>
                        @if ($row->detail)
                            <div class="text-xs mt-0.5 truncate" style="color: var(--text-muted);">{{ $row->detail }}</div>
                        @endif
                    </div>
                    <span class="text-xs shrink-0" style="color: var(--text-muted);">{{ $row->created_at->format('d M, H:i') }}</span>
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
