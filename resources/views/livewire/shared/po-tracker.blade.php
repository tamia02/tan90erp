<?php

use App\Enums\Role;
use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderAcknowledgement;
use App\Models\ValidationIssue;
use App\Models\VendorSubmission;
use App\Services\PurchaseOrderProgressService;
use App\Support\GateStatusLabels;
use App\Support\VendorIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Url(as: 'po', keep: true)]
    public string $poNumber = '';

    #[Url(as: 'direction', keep: true)]
    public string $direction = 'both';

    public string $searchedPo = '';

    public function mount(): void
    {
        $this->searchedPo = trim($this->poNumber);
        $this->direction = in_array($this->direction, ['both', 'inward', 'outward'], true) ? $this->direction : 'both';
    }

    public function search(): void
    {
        $this->searchedPo = trim($this->poNumber);
    }

    public function clearSearch(): void
    {
        $this->poNumber = '';
        $this->searchedPo = '';
    }

    public function setDirection(string $direction): void
    {
        if (! in_array($direction, ['both', 'inward', 'outward'], true)) {
            return;
        }

        $this->direction = $direction;
        $this->searchedPo = trim($this->poNumber);
    }

    public function with(PurchaseOrderProgressService $progress): array
    {
        $user = auth()->user();
        $query = PurchaseOrder::with(['lines', 'acknowledgement'])->orderByDesc('updated_at');

        if ($user?->role === Role::Vendor) {
            $query = VendorIdentity::apply($query, $user);
        }

        if ($this->searchedPo !== '') {
            // PO references are commonly copied with or without spaces or
            // hyphens (for example PORM 2222 vs PORM2222). Resolve the
            // canonical stored key without forcing the user to retype it.
            $normalizedPo = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($this->searchedPo));
            $query->whereRaw("REPLACE(REPLACE(UPPER(po_number), ' ', ''), '-', '') = ?", [$normalizedPo]);
        } elseif ($this->direction !== 'both') {
            $query->whereHas('gateEntries', fn ($q) => $q->where('entry_type', $this->direction));
        }

        $orders = $query->limit($this->searchedPo !== '' ? 1 : 20)->get();
        $rows = $orders->map(fn (PurchaseOrder $po) => $this->row($po, $progress))->values();

        return ['rows' => $rows];
    }

    private function row(PurchaseOrder $po, PurchaseOrderProgressService $progress): array
    {
        $gates = GateEntry::with(['unloadingRecord', 'qcResult', 'grnRecord', 'financeRecord'])
            ->where('po_number', $po->po_number)
            ->when($this->direction !== 'both', fn ($q) => $q->where('entry_type', $this->direction))
            ->orderByDesc('created_at')
            ->get();
        $latestGate = $gates->first();
        $submissions = VendorSubmission::where('po_number', $po->po_number)->latest('created_at')->get();
        $ack = PurchaseOrderAcknowledgement::where('po_number', $po->po_number)->latest('id')->first();
        $issues = $gates->isEmpty()
            ? collect()
            : ValidationIssue::whereIn('gate_entry_id', $gates->pluck('id'))->where('status', 'open')->orderByDesc('created_at')->get();
        $summary = $progress->summarize(collect([$po]))->get($po->po_number);
        $direction = $latestGate?->entry_type ?? ($submissions->isNotEmpty() ? 'inward' : '—');
        $financeRecords = $gates->map(fn (GateEntry $gate) => $gate->financeRecord)->filter();
        $nextAction = $progress->nextAction($ack, $submissions->first(), $latestGate, $latestGate, $financeRecords);

        $steps = [
            ['label' => 'PO created', 'done' => true, 'at' => $po->created_at, 'detail' => 'Store Manager / Procurement'],
            ['label' => 'Released to vendor', 'done' => (bool) $po->released_at, 'at' => $po->released_at, 'detail' => $po->released_at ? 'Vendor portal visible' : 'Draft is still internal'],
            ['label' => 'Vendor acknowledgement', 'done' => (bool) $ack?->acknowledged_at, 'at' => $ack?->acknowledged_at, 'detail' => $ack ? ($ack->accepted ? 'Accepted; production started' : 'Declined by vendor') : 'Waiting for vendor'],
            ['label' => 'Invoice / dispatch', 'done' => $submissions->isNotEmpty(), 'at' => $submissions->first()?->dispatched_at, 'detail' => $submissions->isNotEmpty() ? $submissions->count().' dispatch record(s)' : 'Vendor has not submitted dispatch documents'],
            ['label' => 'Gate entry', 'done' => (bool) $latestGate, 'at' => $latestGate?->created_at, 'detail' => $latestGate?->gate_no ?? 'Waiting for Guard'],
            ['label' => 'Manager approval', 'done' => (bool) $latestGate?->approved_at, 'at' => $latestGate?->approved_at, 'detail' => $latestGate?->approved_at ? 'Entry approved' : 'Pending Store Manager'],
            ['label' => $direction === 'outward' ? 'Dock / loading' : 'Dock / unloading', 'done' => (bool) $latestGate?->dock_assigned_at, 'at' => $latestGate?->dock_assigned_at, 'detail' => $latestGate?->loading_dock ?? 'Dock pending'],
        ];

        if ($direction === 'outward') {
            $steps[] = ['label' => 'Loading complete', 'done' => (bool) $latestGate?->loaded_at, 'at' => $latestGate?->loaded_at, 'detail' => $latestGate?->loaded_at ? 'Documents handed to driver' : 'Store Executive loading pending'];
            $steps[] = ['label' => 'Vehicle exit', 'done' => (bool) $latestGate?->exited_at, 'at' => $latestGate?->exited_at, 'detail' => $latestGate?->exited_at ? 'Guard confirmed exit' : 'Guard confirmation pending'];
        } else {
            $steps[] = ['label' => 'Unloading complete', 'done' => (bool) $latestGate?->unloadingRecord?->completed_at, 'at' => $latestGate?->unloadingRecord?->completed_at, 'detail' => $latestGate?->unloadingRecord?->completed_at ? 'Sent to QC' : 'Store Executive unloading pending'];
            $steps[] = ['label' => 'QC check', 'done' => (bool) $latestGate?->qcResult, 'at' => $latestGate?->qcResult?->created_at, 'detail' => $latestGate?->qcResult ? 'Accepted / hold / rejected quantities recorded' : 'Waiting for QC'];
            $steps[] = ['label' => 'GRN posted', 'done' => (bool) $latestGate?->grnRecord?->posted, 'at' => $latestGate?->grnRecord?->updated_at, 'detail' => $latestGate?->grnRecord?->posted ? 'Stock receipt posted' : 'Waiting for Store Manager'];
            $steps[] = ['label' => 'Putaway complete', 'done' => (bool) $latestGate?->putaway_completed_at, 'at' => $latestGate?->putaway_completed_at, 'detail' => $latestGate?->final_bin ? 'Bin '.$latestGate->final_bin : 'Waiting for Store Executive'];
        }

        $blocker = $this->blocker($po, $latestGate, $issues, $ack, $summary, $nextAction);

        return [
            'po' => $po,
            'direction' => $direction,
            'gate' => $latestGate,
            'issues' => $issues,
            'steps' => $steps,
            'summary' => $summary,
            'blocker' => $blocker,
        ];
    }

    private function blocker(PurchaseOrder $po, ?GateEntry $gate, $issues, ?PurchaseOrderAcknowledgement $ack, ?array $summary, array $nextAction): array
    {
        if (! $po->released_at) {
            return ['tone' => 'warning', 'title' => 'PO is still a draft', 'detail' => 'Release it from PO Master before the vendor can see or acknowledge it.', 'owner' => 'Store Manager', 'route' => 'admin.po'];
        }
        if (! $ack) {
            return ['tone' => 'warning', 'title' => 'Waiting for vendor acknowledgement', 'detail' => 'The vendor must accept the PO before dispatch can start.', 'owner' => 'Vendor', 'route' => 'vendor.purchase-orders'];
        }
        if (! $ack->accepted) {
            return ['tone' => 'critical', 'title' => 'Vendor declined this PO', 'detail' => $ack->remarks ?: 'Ask Store Manager to revise and release the PO again.', 'owner' => 'Store Manager', 'route' => 'admin.po'];
        }
        if ($issues->isNotEmpty()) {
            return ['tone' => 'critical', 'title' => 'Validation issue is blocking this entry', 'detail' => $issues->first()->title.': '.$issues->first()->description, 'owner' => 'Store Manager', 'route' => 'validation.issues'];
        }
        if (! $gate) {
            return ['tone' => 'warning', 'title' => 'Waiting for gate entry', 'detail' => 'Vendor dispatch is recorded only after Guard saves the vehicle entry.', 'owner' => 'Guard', 'route' => 'guard.scan'];
        }

        if ($summary) {
            $complete = in_array($gate->status, ['closed', 'dispatched'], true);

            return ['tone' => $complete ? 'good' : 'warning', 'title' => $nextAction['label'], 'detail' => 'Current status: '.GateStatusLabels::label($gate->status).'. No date-window validation is blocking this step.', 'owner' => $nextAction['owner'], 'route' => $nextAction['route']];
        }

        return ['tone' => 'warning', 'title' => GateStatusLabels::label($gate->status), 'detail' => 'Open the linked gate entry for the next operational action.', 'owner' => 'Operations', 'route' => 'gate-entries.show'];
    }
}; ?>

<div class="space-y-5">
    <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide" style="color: var(--brand);">Tan90 Operations Control</div>
                <h1 class="text-2xl font-bold mt-1" style="color: var(--text-primary);">PO Flow Tracker</h1>
                <p class="text-sm mt-1 max-w-3xl" style="color: var(--text-secondary);">Enter a PO number to see exactly where it is, which team owns the next step, what is blocking it, and every inward or outward milestone already completed.</p>
            </div>
            <span class="rounded-full px-3 py-1 text-xs font-semibold" style="background: var(--brand-bg); color: var(--brand);">Single source of truth</span>
        </div>
        <form wire:submit="search" class="flex flex-col sm:flex-row gap-2 mt-5">
            <label class="sr-only" for="po-tracker-number">PO number</label>
            <input id="po-tracker-number" wire:model="poNumber" class="flex-1 rounded-xl border px-3 py-2.5 text-sm" style="background: var(--surface-1); border-color: var(--border); color: var(--text-primary);" placeholder="Enter PO number, e.g. PORM2222" autocomplete="off" />
            <button type="submit" wire:loading.attr="disabled" class="min-h-11 rounded-xl px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50" style="background: var(--brand);">Check PO status</button>
            @if ($searchedPo)
                <button type="button" wire:click="clearSearch" class="min-h-11 rounded-xl px-4 py-2.5 text-sm font-semibold border" style="border-color: var(--border); color: var(--text-secondary);">Clear</button>
            @endif
        </form>
        <div class="flex flex-wrap gap-2 mt-3" aria-label="Tracker direction filter">
            @foreach (['both' => 'Inward + Outward', 'inward' => 'Recent Inward', 'outward' => 'Recent Outward'] as $key => $label)
                <button type="button" wire:click="setDirection('{{ $key }}')" class="min-h-10 rounded-lg border px-3 py-2 text-xs font-semibold transition-colors" style="background: {{ $direction === $key ? 'var(--brand-bg)' : 'var(--surface-1)' }}; border-color: {{ $direction === $key ? 'var(--brand)' : 'var(--border)' }}; color: {{ $direction === $key ? 'var(--brand)' : 'var(--text-secondary)' }};">{{ $label }}</button>
            @endforeach
        </div>
    </section>

    @forelse ($rows as $row)
        @php($p = $row['summary'])
        <section class="rounded-2xl border p-5" style="background: var(--surface-3); border-color: var(--border);">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h2 class="text-xl font-bold" style="color: var(--text-primary);">{{ $row['po']->po_number }}</h2>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold" style="background: {{ $row['direction'] === 'outward' ? 'var(--status-warning-bg)' : 'var(--brand-bg)' }}; color: {{ $row['direction'] === 'outward' ? 'var(--status-warning)' : 'var(--brand)' }};">{{ ucfirst($row['direction']) }}</span>
                    </div>
                    <div class="text-sm mt-1" style="color: var(--text-secondary);">{{ $row['po']->vendor_name }} · {{ $row['po']->subject ?: 'Purchase Order' }}</div>
                    <div class="text-xs mt-1" style="color: var(--text-muted);">Status: {{ $row['gate'] ? GateStatusLabels::label($row['gate']->status) : ($p['stage'] ?? ($row['po']->status ?: 'Draft')) }} · Gate: {{ $row['gate']?->gate_no ?? 'Not created' }}</div>
                </div>
                @if ($row['gate'])
                    <a href="{{ route('gate-entries.show', $row['gate']) }}" wire:navigate class="min-h-10 inline-flex items-center rounded-lg border px-3 py-2 text-xs font-semibold" style="border-color: var(--border); color: var(--brand);">Open connected entry →</a>
                @endif
            </div>

            <div class="rounded-xl border p-4 mt-4" role="status" style="border-color: var(--status-{{ $row['blocker']['tone'] }}); background: var(--status-{{ $row['blocker']['tone'] }}-bg);">
                <div class="flex items-start gap-3">
                    <div class="min-w-0">
                        <div class="text-sm font-bold" style="color: var(--text-primary);">{{ $row['blocker']['title'] }}</div>
                        <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $row['blocker']['detail'] }}</div>
                        <div class="text-xs mt-2 font-semibold" style="color: var(--text-primary);">Next owner: {{ $row['blocker']['owner'] }}</div>
                    </div>
                    @if ($row['blocker']['route'] && Route::has($row['blocker']['route']))
                        <a href="{{ route($row['blocker']['route'], $row['gate'] ? [$row['gate']] : []) }}" wire:navigate class="min-h-10 shrink-0 inline-flex items-center rounded-lg border px-3 py-2 text-xs font-semibold" style="border-color: currentColor; color: var(--text-primary);">Take action</a>
                    @endif
                </div>
            </div>

            @if ($row['issues']->isNotEmpty())
                <div class="mt-4 rounded-xl border p-4" style="border-color: var(--status-critical); background: var(--status-critical-bg);">
                    <div class="text-sm font-bold" style="color: var(--status-critical);">Open validation issues</div>
                    <div class="space-y-2 mt-2">
                        @foreach ($row['issues'] as $issue)
                            <div class="text-xs" style="color: var(--text-secondary);"><strong>{{ $issue->severity }} · {{ $issue->title }}</strong> — {{ $issue->description }}</div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="mt-5">
                <div class="flex items-center justify-between gap-2 mb-3">
                    <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Complete timeline</h3>
                    <span class="text-xs" style="color: var(--text-muted);">{{ collect($row['steps'])->where('done', true)->count() }} / {{ count($row['steps']) }} completed</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                    @foreach ($row['steps'] as $step)
                        <div class="rounded-xl border p-3" style="border-color: {{ $step['done'] ? 'var(--status-good)' : 'var(--border)' }}; background: {{ $step['done'] ? 'var(--status-good-bg)' : 'var(--surface-2)' }};">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-semibold" style="color: var(--text-primary);">{{ $step['label'] }}</span>
                                <span class="text-[11px] font-bold" style="color: {{ $step['done'] ? 'var(--status-good)' : 'var(--text-muted)' }};">{{ $step['done'] ? 'Done' : 'Pending' }}</span>
                            </div>
                            <div class="text-xs mt-1" style="color: var(--text-secondary);">{{ $step['detail'] }}</div>
                            <div class="text-[11px] mt-2" style="color: var(--text-muted);">{{ $step['at']?->format('d M Y, H:i') ?? 'Not recorded' }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @empty
        <div class="rounded-2xl border p-8 text-center" style="background: var(--surface-3); border-color: var(--border);">
            <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $searchedPo ? 'PO not found' : 'No records for this tab' }}</div>
            <p class="text-xs mt-1" style="color: var(--text-muted);">{{ $searchedPo ? 'Check the exact PO number from PO Master or vendor invoice.' : 'Recent inward and outward PO-linked records will appear here.' }}</p>
        </div>
    @endforelse
</div>
