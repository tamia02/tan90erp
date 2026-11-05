<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\FinanceRecord;
use App\Models\GateEntry;
use App\Models\QcResult;
use App\Models\ValidationIssue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

// Mirrors the React prototype's buildNotices(role, store) — real,
// role-adaptive alerts rather than a generic notification feed.
class NotificationCenter
{
    /**
     * Confirmed live: a Tan90-only user (e.g. the Procurement Reviewer demo
     * login) has a null `role` column -- this was called unconditionally as
     * `NotificationCenter::forRole(auth()->user()->role)` from a
     * non-nullable `Role $role` parameter, a hard TypeError/500 for every
     * such user, not just Procurement.
     *
     * @return array<int, array{title: string, detail: string, tone: string, url?: string}>
     */
    public static function forRole(?Role $role): array
    {
        $userId = auth()->id();
        $cacheKey = 'tan90:notifications:'.($userId ?: 'guest').':'.($role?->value ?? 'tan90');

        // The navigation notification summary is rendered on every full and
        // Livewire request. Keep it fresh, but avoid repeating the same
        // dashboard counts several times while a page is loading.
        $role = auth()->user()?->isAdmin() ? Role::Admin : $role;

        return Cache::remember($cacheKey, now()->addSeconds(15), function () use ($role): array {
            $own = match ($role) {
                Role::Guard => self::guard(),
                Role::StoreExec => self::storeExec(),
                Role::StoreManager => self::storeManager(),
                Role::Finance => self::finance(),
                Role::Qc => self::qc(),
                Role::Vendor => self::vendor(),
                Role::Admin => self::admin(),
                null => self::tan90Fallback(),
                default => [],
            };

            return [...self::visitorApprovals(), ...$own];
        });
    }

    // A Tan90-only user has no entry in the 7-role enum at all -- their
    // access is governed by app/Models/Tan90/MasterData/UserProfile
    // instead, a deliberately separate system (see User::tan90Profile()'s
    // own comment). Procurement is the one persona the client's flow
    // explicitly calls for ("procurement team is notified for
    // issues/closure") — this reads their Tan90 role code directly rather
    // than trying to fold them into the enum.
    private static function tan90Fallback(): array
    {
        $profile = auth()->user()?->tan90Profile()->with('role')->first();

        if ($profile?->role?->code !== 'ROLE-PROCUREMENT') {
            return [];
        }

        $notices = [];

        $open = ValidationIssue::where('status', 'open')->count();
        if ($open > 0) {
            $notices[] = [
                'title' => 'Open validation issues',
                'detail' => "{$open} issue".($open === 1 ? '' : 's')." raised at the gate still need review.",
                'tone' => 'warning',
                'url' => route('procurement.overview'),
            ];
        }

        $closedRecently = GateEntry::where('status', 'closed')->where('updated_at', '>=', now()->subDay())->count();
        if ($closedRecently > 0) {
            $notices[] = [
                'title' => 'Gate entries closed',
                'detail' => "{$closedRecently} gate entr".($closedRecently === 1 ? 'y' : 'ies')." closed in the last 24 hours (GRN posted, goods put away).",
                'tone' => 'good',
                'url' => route('procurement.overview'),
            ];
        }

        // Confirmed live: a delivery closing with real quality problems
        // (defective/rejected units) only ever produced the same generic
        // "closed" notice above -- nothing told Procurement the closure
        // wasn't clean.
        $qcProblems = QcResult::whereHas('gateEntry', fn ($q) => $q->where('updated_at', '>=', now()->subDay()))
            ->where(fn ($q) => $q->where('defective_qty', '>', 0)->orWhere('rejected_qty', '>', 0))
            ->with('gateEntry:id,gate_no,vendor_name')
            ->get();
        if ($qcProblems->isNotEmpty()) {
            $totalDefective = $qcProblems->sum('defective_qty');
            $totalRejected = $qcProblems->sum('rejected_qty');
            $notices[] = [
                'title' => 'Quality problems on recent deliveries',
                'detail' => $qcProblems->count()." deliver".($qcProblems->count() === 1 ? 'y' : 'ies')." in the last 24 hours had defective/rejected units ({$totalDefective} defective, {$totalRejected} rejected total) — ".$qcProblems->map(fn ($qc) => $qc->gateEntry?->gate_no)->filter()->implode(', '),
                'tone' => 'critical',
                'url' => route('procurement.overview'),
            ];
        }

        $outwardUpdates = GateEntry::where('entry_type', 'outward')
            ->whereIn('status', ['dock_assigned', 'loaded', 'dispatched'])
            ->where('updated_at', '>=', now()->subDay())
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['gate_no', 'vehicle_number', 'status', 'expected_delivery_date', 'updated_at']);
        foreach ($outwardUpdates as $entry) {
            $notices[] = [
                'title' => 'Outward dispatch update',
                'detail' => "{$entry->gate_no} · ".GateStatusLabels::label($entry->status).($entry->expected_delivery_date ? " · EDD {$entry->expected_delivery_date->format('d M Y')}" : '').($entry->vehicle_number ? " · {$entry->vehicle_number}" : ''),
                'tone' => $entry->status === 'dispatched' ? 'good' : 'warning',
                'url' => route('gate-entries.show', $entry),
            ];
        }

        return $notices;
    }

    // The visitor's host can be anyone regardless of role, so this check
    // runs for every role rather than living inside one role's method --
    // previously nobody was ever notified about a visitor asking for them
    // since "Person To Meet" wasn't a real, notifiable person at all.
    private static function visitorApprovals(): array
    {
        $notices = [];
        $userId = auth()->id();

        if (! $userId) {
            return $notices;
        }

        $pending = GateEntry::where('entry_type', 'visitor')->where('status', 'pending_validation')->where('visitor_host_id', $userId)->count();
        if ($pending > 0) {
            $notices[] = [
                'title' => 'Visitors waiting for you',
                'detail' => "{$pending} visitor".($pending === 1 ? '' : 's')." asked to see you and ".($pending === 1 ? 'is' : 'are')." waiting at the gate for your approval.",
                'tone' => 'warning',
                'url' => route('visitor-approvals'),
            ];
        }

        return $notices;
    }

    // Confirmed live: this role had no case at all here (silently fell
    // through to the empty default), so Store Exec always saw "Nothing
    // needs your attention" no matter how overdue a dock/unloading step
    // was -- e.g. an entry that missed its SLA deadline days ago while
    // still waiting for a dock.
    private static function storeExec(): array
    {
        $notices = [];

        $breached = GateEntry::where('entry_type', 'inward')
            ->whereIn('status', ['dock_assigned', 'allotted', 'unloading'])
            ->where('sla_deadline', '<', now())
            ->count();

        if ($breached > 0) {
            $notices[] = [
                'title' => 'SLA breached awaiting loading/unloading',
                'detail' => "{$breached} inward entr".($breached === 1 ? 'y has' : 'ies have')." passed its SLA deadline still waiting for physical unloading to finish.",
                'tone' => 'critical',
                'url' => route('unloading.desk'),
            ];
        }

        $readyToLoad = GateEntry::where('entry_type', 'outward')->where('status', 'dock_assigned')->count();
        if ($readyToLoad > 0) {
            $notices[] = [
                'title' => 'Outward dispatches ready to load',
                'detail' => "{$readyToLoad} outward dispatch".($readyToLoad === 1 ? '' : 'es')." approved and assigned a bay, waiting to be loaded.",
                'tone' => 'warning',
                'url' => route('unloading.outward'),
            ];
        }

        $awaitingPutaway = GateEntry::whereNull('putaway_completed_at')
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->where('status', 'grn_posted')->whereHas('grnRecord', fn ($q) => $q->where('posted', true));
                })->orWhere(function ($query) {
                    $query->where('status', 'qc_done')->whereHas('qcResult', fn ($q) => $q->where('qc_hold_qty', '>', 0));
                });
            })
            ->count();
        if ($awaitingPutaway > 0) {
            $notices[] = [
                'title' => 'Putaway pending',
                'detail' => "{$awaitingPutaway} entr".($awaitingPutaway === 1 ? 'y is' : 'ies are')." waiting for physical putaway; QC-held goods can proceed while GRN remains pending.",
                'tone' => 'warning',
                'url' => route('unloading.putaway'),
            ];
        }

        return $notices;
    }

    private static function guard(): array
    {
        $notices = [];

        // Previously only surfaced SLA breaches — a guard had no way to tell
        // from the notification summary which entries actually need
        // attention (pending validation, held with open hard-fail/red-flag
        // issues) versus entries just moving normally through the pipeline.
        $pending = GateEntry::where('status', 'pending_validation')->count();
        if ($pending > 0) {
            $notices[] = [
                'title' => 'Entries pending validation',
                'detail' => "{$pending} gate entr".($pending === 1 ? 'y is' : 'ies are')." held pending validation — open Guard Entries to see why.",
                'tone' => 'warning',
                'url' => route('guard.entries', ['status' => 'pending_validation']),
            ];
        }

        $breached = GateEntry::where('status', '!=', 'closed')->where('sla_deadline', '<', now())->count();
        if ($breached > 0) {
            $notices[] = [
                'title' => 'SLA breached',
                'detail' => "{$breached} gate entr".($breached === 1 ? 'y has' : 'ies have')." breached the 12-hour GRN SLA.",
                'tone' => 'critical',
                'url' => route('guard.entries', ['breached' => 1]),
            ];
        }

        $readyForExit = GateEntry::where('entry_type', 'outward')->where('status', 'loaded')->count();
        if ($readyForExit > 0) {
            $notices[] = [
                'title' => 'Vehicles ready to exit',
                'detail' => "{$readyForExit} outward vehicle".($readyForExit === 1 ? '' : 's')." loaded and ready — confirm exit on the gate entry once it leaves.",
                'tone' => 'good',
                'url' => route('guard.entries', ['status' => 'loaded']),
            ];
        }

        $visitorsApproved = GateEntry::where('entry_type', 'visitor')->where('status', 'validated')->count();
        if ($visitorsApproved > 0) {
            $notices[] = [
                'title' => 'Visitors approved, ready to let in',
                'detail' => "{$visitorsApproved} visitor".($visitorsApproved === 1 ? '' : 's')." approved by their host — allow entry on the gate entry.",
                'tone' => 'good',
                'url' => route('guard.entries', ['status' => 'validated']),
            ];
        }

        $visitorsOnSite = GateEntry::where('entry_type', 'visitor')->where('status', 'checked_in')->count();
        if ($visitorsOnSite > 0) {
            $notices[] = [
                'title' => 'Visitors on site',
                'detail' => "{$visitorsOnSite} visitor".($visitorsOnSite === 1 ? '' : 's')." checked in and still on the premises — check out on the gate entry once they leave.",
                'tone' => 'warning',
                'url' => route('guard.entries', ['status' => 'checked_in']),
            ];
        }

        return $notices;
    }

    private static function storeManager(): array
    {
        $notices = [];

        // The most upstream gap under the new flow: neither an inward nor
        // an outward entry can move forward until Store Manager explicitly
        // approves it (Entry Approvals) -- previously a clean entry needed
        // no such action, so this notice didn't exist at all.
        $awaitingApproval = GateEntry::whereIn('entry_type', ['inward', 'outward'])->where('status', 'pending_validation')->count();
        if ($awaitingApproval > 0) {
            $notices[] = [
                'title' => 'Entries awaiting approval',
                'detail' => "{$awaitingApproval} gate entr".($awaitingApproval === 1 ? 'y is' : 'ies are')." waiting for you to approve.",
                'tone' => 'warning',
                'url' => route('store-manager.entry-approvals'),
            ];
        }

        $awaitingInwardDock = GateEntry::where('entry_type', 'inward')->where('status', 'validated')->count();
        if ($awaitingInwardDock > 0) {
            $notices[] = [
                'title' => 'Inward docks to assign',
                'detail' => "{$awaitingInwardDock} approved inward entr".($awaitingInwardDock === 1 ? 'y is' : 'ies are')." waiting for your dock assignment.",
                'tone' => 'warning',
                'url' => route('store-manager.entry-approvals'),
            ];
        }

        $dispatchedToday = GateEntry::where('entry_type', 'outward')->where('status', 'dispatched')->whereDate('exited_at', today())->count();
        if ($dispatchedToday > 0) {
            $notices[] = [
                'title' => 'Dispatched today',
                'detail' => "{$dispatchedToday} outward vehicle".($dispatchedToday === 1 ? '' : 's')." left the premises today.",
                'tone' => 'good',
                'url' => route('gate-entries.index'),
            ];
        }

        $open = ValidationIssue::where('status', 'open')->count();
        if ($open > 0) {
            $notices[] = ['title' => 'Open validation issues', 'detail' => "{$open} issue".($open === 1 ? '' : 's')." raised at the gate still need review.", 'tone' => 'warning', 'url' => route('validation.issues')];
        }
        $awaitingGrn = GateEntry::where('status', 'qc_done')->count();
        if ($awaitingGrn > 0) {
            $notices[] = ['title' => 'GRN pending', 'detail' => "{$awaitingGrn} QC-checked entr".($awaitingGrn === 1 ? 'y is' : 'ies are')." waiting to be posted.", 'tone' => 'good', 'url' => route('grn.check')];
        }

        $vendorEscalations = \App\Models\GrnRecord::where('vendor_response', 'escalated')->whereHas('gateEntry', fn ($q) => $q->where('status', '!=', 'closed'))->count();
        if ($vendorEscalations > 0) {
            $notices[] = ['title' => 'Vendor GRN escalations', 'detail' => "{$vendorEscalations} posted GRN".($vendorEscalations === 1 ? ' has' : 's have')." been escalated by a vendor and need your review.", 'tone' => 'critical', 'url' => route('grn.check')];
        }

        return $notices;
    }

    private static function finance(): array
    {
        $notices = [];
        $pending = FinanceRecord::where('vendor_status', 'pending')->count();
        if ($pending > 0) {
            $notices[] = ['title' => 'Payables pending review', 'detail' => "{$pending} vendor payable".($pending === 1 ? '' : 's')." still marked pending.", 'tone' => 'warning'];
        }

        // Store Manager → Finance Controller escalation path.
        $escalated = ValidationIssue::where('status', 'escalated')->count();
        if ($escalated > 0) {
            $notices[] = ['title' => 'Issues escalated from Store Manager', 'detail' => "{$escalated} validation issue".($escalated === 1 ? '' : 's')." escalated and need Finance Controller review.", 'tone' => 'critical'];
        }

        return $notices;
    }

    private static function qc(): array
    {
        $notices = [];
        $inQueue = GateEntry::where('status', 'grn')->count();
        if ($inQueue > 0) {
            $notices[] = ['title' => 'QC queue', 'detail' => "{$inQueue} deliver".($inQueue === 1 ? 'y is' : 'ies are')." waiting for the QC split.", 'tone' => 'good'];
        }

        return $notices;
    }

    private static function vendor(): array
    {
        $notices = [];
        $vendor = auth()->user();
        $vendorName = $vendor?->name;

        // Confirmed live: a vendor got no signal at all that a new PO had
        // been released to them -- they had to already know the PO number
        // and type it in by hand.
        $newPos = VendorIdentity::apply(\App\Models\PurchaseOrder::query(), $vendor)
            ->whereNotNull('released_at')
            ->where('released_at', '>=', now()->subDays(3))
            ->count();
        if ($newPos > 0) {
            $notices[] = [
                'title' => 'New purchase order released',
                'detail' => "{$newPos} PO".($newPos === 1 ? '' : 's')." released to you in the last 3 days — review and acknowledge.",
                'tone' => 'good',
                'url' => route('vendor.purchase-orders'),
            ];
        }

        // A return is only ever raised for rejected_qty (see QcService) --
        // confirmed live, this said "rejected quantity on QC hold" (hold
        // was 0 on the actual delivery that triggered it) and never
        // mentioned defective quantity, a separate deduction on the same
        // delivery that the vendor has no visibility into here at all.
        $pendingReturnResults = QcResult::whereHas(
            'gateEntry',
            fn ($q) => VendorIdentity::apply($q, $vendor),
        )->where('return_status', 'pending')->get();

        if ($pendingReturnResults->isNotEmpty()) {
            $totalRejected = $pendingReturnResults->sum('rejected_qty');
            $totalDefective = $pendingReturnResults->sum('defective_qty');
            $count = $pendingReturnResults->count();
            $notices[] = [
                'title' => 'Purchase return needed',
                'detail' => "{$count} deliver".($count === 1 ? 'y has' : 'ies have')." {$totalRejected} unit".($totalRejected === 1 ? '' : 's')." rejected on QC"
                    .($totalDefective > 0 ? " (plus {$totalDefective} defective, deducted separately)" : '')
                    .' — action the purchase return from your dashboard.',
                'tone' => 'critical',
            ];
        }

        // Confirmed live: vendor was only ever notified about a return --
        // a normal, no-issue closure (GRN posted, goods put away) never
        // reached them at all, even though the flow calls for the vendor
        // to hear about closure either way.
        $closedRecently = VendorIdentity::apply(GateEntry::query(), $vendor)->where('status', 'closed')->where('updated_at', '>=', now()->subDay())->count();
        if ($closedRecently > 0) {
            $notices[] = [
                'title' => 'Delivery closed',
                'detail' => "{$closedRecently} of your deliver".($closedRecently === 1 ? 'y has' : 'ies have')." been fully closed — GRN posted and goods put away.",
                'tone' => 'good',
            ];
        }

        $progress = VendorIdentity::apply(GateEntry::query(), $vendor)
            ->whereIn('status', ['pending_validation', 'validated', 'dock_assigned', 'allotted', 'unloading', 'grn', 'qc_done', 'grn_posted'])
            ->where('updated_at', '>=', now()->subDays(3))
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['gate_no', 'po_number', 'status', 'updated_at']);
        foreach ($progress as $entry) {
            $notices[] = [
                'title' => 'PO progress update',
                'detail' => "{$entry->po_number} · ".GateStatusLabels::label($entry->status)." (Gate {$entry->gate_no})",
                'tone' => in_array($entry->status, ['pending_validation', 'qc_done'], true) ? 'warning' : 'good',
                'url' => route('vendor.submissions'),
            ];
        }

        return $notices;
    }

    /** Surfaces failures from the last 30-minute Zoho Inventory cron run — the commands cache their own result under these keys, see PushZohoInventoryData / SyncZohoInventoryMasterData / SyncZohoInventoryPurchaseOrders. */
    private static function admin(): array
    {
        $notices = [];

        if (! config('services.zoho.inventory.organization_id') || ! config('services.zoho.inventory.refresh_token')) {
            return $notices;
        }

        $runs = [
            'push-data' => 'Zoho Inventory push',
            'sync-master-data' => 'Zoho Inventory vendor/item sync',
            'sync-purchase-orders' => 'Zoho Inventory PO sync',
        ];

        foreach ($runs as $key => $label) {
            $last = Cache::get("zoho_inventory_last_run:{$key}");

            if ($last && ($last['failed'] ?? 0) > 0) {
                $notices[] = [
                    'title' => "{$label} had failures",
                    'detail' => "{$last['failed']} failed on the run at ".Carbon::parse($last['at'])->format('d M, H:i').' — check storage/logs/laravel.log for details.',
                    'tone' => 'critical',
                ];
            }
        }

        return $notices;
    }
}
