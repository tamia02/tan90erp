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
    /** @return array<int, array{title: string, detail: string, tone: string, url?: string}> */
    public static function forRole(Role $role): array
    {
        return match ($role) {
            Role::Guard => self::guard(),
            Role::StoreExec => self::storeExec(),
            Role::StoreManager => self::storeManager(),
            Role::Finance => self::finance(),
            Role::Qc => self::qc(),
            Role::Vendor => self::vendor(),
            Role::Admin => self::admin(),
            default => [],
        };
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
            ->whereIn('status', ['validated', 'dock_assigned', 'allotted', 'unloading'])
            ->where('sla_deadline', '<', now())
            ->count();

        if ($breached > 0) {
            $notices[] = [
                'title' => 'SLA breached awaiting loading/unloading',
                'detail' => "{$breached} inward entr".($breached === 1 ? 'y has' : 'ies have')." passed its SLA deadline still waiting on a dock or unloading step.",
                'tone' => 'critical',
                'url' => route('unloading.loading-desk'),
            ];
        }

        $awaitingDock = GateEntry::where('entry_type', 'inward')->where('status', 'validated')->count();
        if ($awaitingDock > 0) {
            $notices[] = [
                'title' => 'Vehicles awaiting a dock',
                'detail' => "{$awaitingDock} cleared vehicle".($awaitingDock === 1 ? '' : 's')." waiting to be assigned a dock.",
                'tone' => 'warning',
                'url' => route('unloading.loading-desk'),
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
        $vendorName = auth()->user()?->name;
        $pendingReturns = QcResult::whereHas(
            'gateEntry',
            fn ($q) => $q->where('vendor_name', $vendorName),
        )->where('return_status', 'pending')->count();

        if ($pendingReturns > 0) {
            $notices[] = [
                'title' => 'Purchase return needed',
                'detail' => "{$pendingReturns} deliver".($pendingReturns === 1 ? 'y has' : 'ies have')." rejected quantity on QC hold — action the purchase return from your dashboard.",
                'tone' => 'critical',
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
