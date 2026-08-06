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
    /** @return array<int, array{title: string, detail: string, tone: string}> */
    public static function forRole(Role $role): array
    {
        return match ($role) {
            Role::Guard => self::guard(),
            Role::StoreManager => self::storeManager(),
            Role::Finance => self::finance(),
            Role::Qc => self::qc(),
            Role::Vendor => self::vendor(),
            Role::Admin => self::admin(),
            default => [],
        };
    }

    private static function guard(): array
    {
        $notices = [];
        $breached = GateEntry::where('status', '!=', 'closed')->where('sla_deadline', '<', now())->count();
        if ($breached > 0) {
            $notices[] = ['title' => 'SLA breached', 'detail' => "{$breached} gate entr".($breached === 1 ? 'y has' : 'ies have')." breached the 12-hour GRN SLA.", 'tone' => 'critical'];
        }

        return $notices;
    }

    private static function storeManager(): array
    {
        $notices = [];
        $open = ValidationIssue::where('status', 'open')->count();
        if ($open > 0) {
            $notices[] = ['title' => 'Open validation issues', 'detail' => "{$open} issue".($open === 1 ? '' : 's')." raised at the gate still need review.", 'tone' => 'warning'];
        }
        $awaitingGrn = GateEntry::where('status', 'qc_done')->count();
        if ($awaitingGrn > 0) {
            $notices[] = ['title' => 'GRN pending', 'detail' => "{$awaitingGrn} QC-checked entr".($awaitingGrn === 1 ? 'y is' : 'ies are')." waiting to be posted.", 'tone' => 'good'];
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
