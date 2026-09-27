<?php

namespace App\Services;

use App\Models\GateEntry;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderAcknowledgement;
use App\Models\VendorSubmission;
use App\Support\GateStatusLabels;
use Illuminate\Support\Collection;

/**
 * Builds the connected PO view used by Store Manager and vendor surfaces.
 * The legacy tables intentionally retain their string business key, but all
 * modules now pass through this service so a PO number resolves one coherent
 * lifecycle instead of separate, drifting lists.
 */
class PurchaseOrderProgressService
{
    /**
     * @param  Collection<int, PurchaseOrder>  $orders
     * @return Collection<string, array<string, mixed>>
     */
    public function summarize(Collection $orders, ?string $vendorName = null): Collection
    {
        $orders = $orders->filter(fn ($order) => $order instanceof PurchaseOrder && $order->po_number)->values();
        $poNumbers = $orders->pluck('po_number')->unique()->values();

        if ($poNumbers->isEmpty()) {
            return collect();
        }

        $ackQuery = PurchaseOrderAcknowledgement::whereIn('po_number', $poNumbers);
        $submissionQuery = VendorSubmission::whereIn('po_number', $poNumbers);
        $gateQuery = GateEntry::whereIn('po_number', $poNumbers);

        if ($vendorName !== null) {
            $ackQuery->where('vendor_name', $vendorName);
            $submissionQuery->where('vendor_name', $vendorName);
            $gateQuery->where('vendor_name', $vendorName);
        }

        $acks = $ackQuery->get()->keyBy('po_number');
        $submissions = $submissionQuery->latest('created_at')->get()->groupBy('po_number');
        $gates = $gateQuery
            ->with(['unloadingRecord', 'qcResult', 'grnRecord', 'financeRecord', 'validationIssues'])
            ->latest('created_at')
            ->get()
            ->groupBy('po_number');

        return $orders->mapWithKeys(function (PurchaseOrder $po) use ($acks, $submissions, $gates) {
            $poSubmissions = $submissions->get($po->po_number, collect());
            $poGates = $gates->get($po->po_number, collect());
            $ack = $acks->get($po->po_number);
            $orderedQty = (float) $po->lines->sum('quantity');
            $billedQty = (float) $poSubmissions->sum(fn (VendorSubmission $row) => (float) ($row->invoice_qty ?? 0));
            $gateQty = (float) $poGates->where('entry_type', 'inward')->sum(fn (GateEntry $row) => (float) ($row->invoice_qty ?? 0));
            $acceptedQty = (float) $poGates->sum(fn (GateEntry $row) => (float) ($row->qcResult?->accepted_qty ?? 0));
            $grnQty = (float) $poGates->sum(fn (GateEntry $row) => $row->grnRecord?->posted ? (float) ($row->grnRecord?->accepted_qty ?? 0) : 0);
            $hasQc = $poGates->contains(fn (GateEntry $row) => $row->qcResult !== null);
            $receivedQty = $hasQc ? $acceptedQty : $gateQty;
            $latestSubmission = $poSubmissions->first();
            $activeGate = $this->actionableGate($poGates);
            $latestGate = $poGates->first();
            $financeRecords = $poGates->map(fn (GateEntry $gate) => $gate->financeRecord)->filter();
            $invoicedAmount = (float) $poSubmissions->sum(fn (VendorSubmission $row) => (float) ($row->invoice_amount ?? 0));
            $billingAmountReview = $this->invoiceAmountReview($po, $poSubmissions, $orderedQty, $billedQty);
            $payable = (float) $financeRecords->sum('final_payable');
            $gateWarnings = $this->gateWarnings($poGates, $orderedQty);

            if ($activeGate) {
                $stage = GateStatusLabels::label($activeGate->status);
            } elseif ($latestGate && in_array($latestGate->status, ['closed', 'rejected', 'dispatched'], true)) {
                $stage = GateStatusLabels::label($latestGate->status);
            } elseif ($latestSubmission?->dispatched_at) {
                $stage = 'Dispatched - awaiting gate entry';
            } elseif ($ack?->accepted) {
                $stage = 'Awaiting vendor dispatch';
            } elseif ($ack) {
                $stage = 'PO declined';
            } else {
                $stage = 'Awaiting vendor acknowledgement';
            }

            $next = $this->nextAction($ack, $latestSubmission, $activeGate, $latestGate, $financeRecords);

            return [$po->po_number => [
                'po' => $po,
                'ack' => $ack,
                'submissions' => $poSubmissions,
                'gates' => $poGates,
                'latest_submission' => $latestSubmission,
                'active_gate' => $activeGate,
                'latest_gate' => $latestGate,
                'stage' => $stage,
                'next_action' => $next,
                'ordered_qty' => $orderedQty,
                'billed_qty' => $billedQty,
                'gate_qty' => $gateQty,
                'accepted_qty' => $acceptedQty,
                'grn_qty' => $grnQty,
                'remaining_qty' => max($orderedQty - $receivedQty, 0),
                'billed_pct' => $orderedQty > 0 ? min(100, (int) round($billedQty / $orderedQty * 100)) : 0,
                'received_pct' => $orderedQty > 0 ? min(100, (int) round($receivedQty / $orderedQty * 100)) : 0,
                'invoice_count' => $this->invoiceCount($poSubmissions, $poGates),
                'invoiced_amount' => $invoicedAmount,
                ...$billingAmountReview,
                'payable' => $payable,
                'open_issue_count' => $poGates->flatMap(fn (GateEntry $gate) => $gate->validationIssues)->where('status', 'open')->count(),
                ...$gateWarnings,
            ]];
        });
    }

    /**
     * Once all ordered units are billed, reconcile the sum of split invoice
     * values to the PO total. Small currency rounding below one paisa is ignored.
     *
     * @param  Collection<int, VendorSubmission>  $submissions
     * @return array{po_total_amount: float, invoice_amount_variance: float, invoice_amount_review_required: bool}
     */
    public function invoiceAmountReview(PurchaseOrder $po, Collection $submissions, float $orderedQty, float $billedQty): array
    {
        $poTotal = round($po->grandTotal(), 2);
        $billedTotal = round((float) $submissions->sum(fn (VendorSubmission $row) => (float) ($row->invoice_amount ?? 0)), 2);
        $variance = round($billedTotal - $poTotal, 2);

        return [
            'po_total_amount' => $poTotal,
            'invoice_amount_variance' => $variance,
            'invoice_amount_review_required' => $orderedQty > 0
                && $billedQty >= $orderedQty - 0.0001
                && abs($variance) > 0.01,
        ];
    }

    /**
     * Produce review cues without changing or excluding any operational record.
     * Repeated invoices can be legitimate corrections, so they are described
     * as repeated references rather than automatically treated as duplicates.
     *
     * @param  Collection<int, GateEntry>  $gates
     * @return array{gate_overage_qty: float, pending_validation_count: int, repeated_invoices: array<int, array{invoice_number: string, gate_count: int, pending_count: int, gate_numbers: array<int, string>}>}
     */
    public function gateWarnings(Collection $gates, float $orderedQty): array
    {
        $inwardGates = $gates->where('entry_type', 'inward');
        $gateQty = (float) $inwardGates->sum(fn (GateEntry $gate) => (float) ($gate->invoice_qty ?? 0));
        $repeatedInvoices = $inwardGates
            ->filter(fn (GateEntry $gate) => trim((string) $gate->invoice_number) !== '')
            ->groupBy(fn (GateEntry $gate) => strtolower(trim((string) $gate->invoice_number)))
            ->filter(fn (Collection $rows) => $rows->count() > 1)
            ->map(fn (Collection $rows) => [
                'invoice_number' => (string) $rows->first()->invoice_number,
                'gate_count' => $rows->count(),
                'pending_count' => $rows->where('status', 'pending_validation')->count(),
                'gate_numbers' => $rows->pluck('gate_no')->filter()->unique()->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'gate_overage_qty' => (float) max($gateQty - $orderedQty, 0.0),
            'pending_validation_count' => $inwardGates->where('status', 'pending_validation')->count(),
            'repeated_invoices' => $repeatedInvoices,
        ];
    }

    private function actionableGate(Collection $gates): ?GateEntry
    {
        $priority = [
            'pending_validation' => 10,
            'validated' => 20,
            'dock_assigned' => 30,
            'allotted' => 40,
            'unloading' => 50,
            'grn' => 60,
            'qc_done' => 70,
            'grn_posted' => 80,
            'loaded' => 90,
        ];

        return $gates
            ->filter(fn (GateEntry $gate) => isset($priority[$gate->status]))
            ->sortBy(fn (GateEntry $gate) => [$priority[$gate->status], -$gate->created_at->timestamp])
            ->first();
    }

    private function invoiceCount(Collection $submissions, Collection $gates): int
    {
        $numbers = $submissions->pluck('invoice_number')->merge($gates->pluck('invoice_number'))->filter()->unique();

        return $numbers->count();
    }

    /** @return array{label: string, owner: string, route: ?string} */
    public function nextAction($ack, ?VendorSubmission $submission, ?GateEntry $gate, ?GateEntry $latestGate, Collection $financeRecords): array
    {
        if (! $ack) {
            return ['label' => 'Await vendor acknowledgement', 'owner' => 'Vendor', 'route' => 'vendor.purchase-orders'];
        }

        if (! $ack->accepted) {
            return ['label' => 'Revise PO or request vendor acceptance', 'owner' => 'Store Manager', 'route' => 'admin.po'];
        }

        if ($gate) {
            if ($gate->entry_type === 'outward') {
                return match ($gate->status) {
                    'pending_validation' => ['label' => 'Approve outward gate entry / resolve validation', 'owner' => 'Store Manager', 'route' => 'store-manager.entry-approvals'],
                    'validated' => ['label' => 'Assign outward loading dock', 'owner' => 'Store Manager', 'route' => 'store-manager.entry-approvals'],
                    'dock_assigned' => ['label' => 'Start and complete outward loading', 'owner' => 'Store Executive', 'route' => 'unloading.outward'],
                    'loaded' => ['label' => 'Confirm vehicle exit', 'owner' => 'Security Guard', 'route' => 'gate-entries.show'],
                    'dispatched' => ['label' => 'Outward dispatch complete; notify SCM / Sales with EDD', 'owner' => 'SCM / Sales', 'route' => 'gate-entries.show'],
                    default => ['label' => GateStatusLabels::label($gate->status), 'owner' => 'Operations', 'route' => 'gate-entries.show'],
                };
            }

            return match ($gate->status) {
                'pending_validation' => ['label' => 'Approve gate entry / resolve validation', 'owner' => 'Store Manager', 'route' => 'store-manager.entry-approvals'],
                'validated' => ['label' => 'Assign inward dock', 'owner' => 'Store Manager', 'route' => 'store-manager.entry-approvals'],
                'dock_assigned', 'allotted', 'unloading' => ['label' => 'Complete unloading and send to QC', 'owner' => 'Store Executive', 'route' => 'unloading.desk'],
                'grn' => ['label' => 'Complete physical and document QC', 'owner' => 'QC', 'route' => 'qc.queue'],
                'qc_done' => ['label' => 'Post GRN to stock', 'owner' => 'Store Manager', 'route' => 'grn.check'],
                'grn_posted' => ['label' => 'Confirm putaway bin and close receipt', 'owner' => 'Store Executive', 'route' => 'unloading.putaway'],
                // Loading is complete at this point. The next owner is Security,
                // which must confirm that the vehicle has actually exited before
                // the outward record becomes dispatched/closed.
                'loaded' => ['label' => 'Confirm vehicle exit', 'owner' => 'Security Guard', 'route' => 'gate-entries.show'],
                default => ['label' => GateStatusLabels::label($gate->status), 'owner' => 'Operations', 'route' => 'gate-entries.show'],
            };
        }

        if ($latestGate?->status === 'closed') {
            return $financeRecords->contains(fn ($record) => $record->vendor_status !== 'cleared')
                ? ['label' => 'Review payable and clear payment', 'owner' => 'Finance', 'route' => 'finance.review']
                : ['label' => 'PO flow closed', 'owner' => 'Operations', 'route' => 'gate-entries.show'];
        }

        if ($latestGate?->status === 'dispatched') {
            return ['label' => 'Await delivery closure / POD', 'owner' => 'SCM / Sales', 'route' => 'gate-entries.show'];
        }

        if ($submission?->dispatched_at) {
            return ['label' => 'Create gate entry against the invoice', 'owner' => 'Security Guard', 'route' => 'guard.scan'];
        }

        if ($financeRecords->contains(fn ($record) => $record->vendor_status !== 'cleared')) {
            return ['label' => 'Review payable and clear payment', 'owner' => 'Finance', 'route' => 'finance.review'];
        }

        return ['label' => 'Await vendor dispatch', 'owner' => 'Vendor', 'route' => 'vendor.submissions'];
    }
}
