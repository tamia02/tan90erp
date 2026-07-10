<?php
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use App\Models\AuditLogEntry;
use App\Models\FinanceRecord;
use App\Models\PurchaseOrder;
use App\Models\VendorSubmission;

new #[Layout('layouts.app')] class extends Component
{
    private const ACTIVITY_KEYWORDS = ['Vendor submission', 'Vendor stock', 'Vendor User'];

    public bool $showAllActivity = false;

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

        return [
            'submissions' => $allSubmissions->sortByDesc('created_at')->take(5)->values(),
            'fulfillment' => $fulfillment,
            'financeRecords' => FinanceRecord::where('vendor_name', $vendorName)->orderByDesc('created_at')->take(5)->get(),
            'isAdvanced' => auth()->user()->isAdvancedVendor(),
            'activityTotal' => $activity->count(),
            'recentActivity' => $this->showAllActivity ? $activity : $activity->take(5),
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
    <p class="text-xs mb-3" style="color: var(--text-secondary);">Purchase orders fulfilled across multiple invoices/dispatches.</p>
    <div class="space-y-3 mb-6">
        @forelse ($fulfillment as $f)
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
            <p class="text-sm py-4 text-center rounded-lg border" style="color: var(--text-muted); border-color: var(--border); background: var(--surface-3);">No PO-linked submissions yet.</p>
        @endforelse
    </div>

    <h2 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">Financial Status</h2>
    <div class="space-y-2 mb-6">
        @forelse ($financeRecords as $fr)
            <div class="rounded-lg border p-4 flex items-center justify-between text-sm" style="background: var(--surface-3); border-color: var(--border);">
                <div>
                    <div style="color: var(--text-primary);">{{ $fr->invoice_number }} — Final payable: {{ number_format($fr->final_payable, 2) }}</div>
                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">Invoice value {{ number_format($fr->invoice_value, 2) }}</div>
                </div>
                <span class="text-xs font-semibold capitalize" style="color: {{ $fr->vendor_status === 'cleared' ? 'var(--status-good)' : 'var(--status-warning)' }};">{{ $fr->vendor_status }}</span>
            </div>
        @empty
            <p class="text-sm py-4 text-center rounded-lg border" style="color: var(--text-muted); border-color: var(--border); background: var(--surface-3);">No finance records yet.</p>
        @endforelse
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
                <div class="py-3 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ $row->action }}</div>
                        @if ($row->detail)
                            <div class="text-xs mt-0.5 truncate" style="color: var(--text-muted);">{{ $row->detail }}</div>
                        @endif
                    </div>
                    <span class="text-xs shrink-0" style="color: var(--text-muted);">{{ $row->created_at->format('d M, H:i') }}</span>
                </div>
            @empty
                <p class="text-sm py-4" style="color: var(--text-muted);">No activity recorded yet.</p>
            @endforelse
        </div>
    </div>

    @if ($isAdvanced)
        <h2 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">Advanced Analytics</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-xs" style="color: var(--text-muted);">Avg PO fulfillment</div>
                <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $fulfillment->count() ? round($fulfillment->avg('pct')) : 0 }}%</div>
            </div>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-xs" style="color: var(--text-muted);">Open POs</div>
                <div class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">{{ $fulfillment->where('remaining', '>', 0)->count() }}</div>
            </div>
            <div class="rounded-lg border p-4" style="background: var(--surface-3); border-color: var(--border);">
                <div class="text-xs" style="color: var(--text-muted);">Cleared payables</div>
                <div class="text-2xl font-semibold mt-1" style="color: var(--status-good);">{{ number_format($financeRecords->where('vendor_status', 'cleared')->sum('final_payable'), 2) }}</div>
            </div>
        </div>
    @endif
</div>
