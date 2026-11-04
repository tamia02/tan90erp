<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\Batch;
use App\Models\Forge\FinalQcResult;
use App\Models\Forge\Machine;
use App\Models\Forge\ProductionPlan;
use App\Models\Forge\WastageRecord;
use App\Services\Access\AccessControlService;
use App\Services\Forge\WorkOrderListing;
use App\Support\SiteLocation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ForgeExportController extends Controller
{
    public function __construct(private AccessControlService $access, private WorkOrderListing $workOrders) {}

    public function plans(Request $request): StreamedResponse
    {
        $this->authorizeExport($request, 'forge.plan.export');
        $site = SiteLocation::forUser($request->user());
        $rows = ProductionPlan::with('finishedGood')->when($site !== null, fn ($query) => $query->where('site_id', $site))
            ->latest()->lazy(200)
            ->map(fn (ProductionPlan $plan) => [$plan->plan_number, $plan->finishedGood?->name, $plan->target_qty, $plan->uom, $plan->due_date?->toDateString(), $plan->status]);

        return $this->csv('production-plans.csv', ['Plan', 'Finished Good', 'Target Quantity', 'UOM', 'Due Date', 'Status'], $rows);
    }

    public function workOrders(Request $request): StreamedResponse
    {
        $this->authorizeExport($request, 'forge.workorder.export');
        $rows = $this->workOrders->query($request)->latest()->lazy(200)
            ->map(fn ($workOrder) => [$workOrder->wo_number, $workOrder->finishedGood?->name, $workOrder->target_qty, $workOrder->good_qty, $workOrder->status]);

        return $this->csv('production-work-orders.csv', ['Work Order', 'Finished Good', 'Target Quantity', 'Good Quantity', 'Status'], $rows);
    }

    public function machines(Request $request): StreamedResponse
    {
        $this->authorizeExport($request, 'forge.machine.export');
        $site = SiteLocation::forUser($request->user());
        $rows = Machine::with('site')->when($site !== null, fn ($query) => $query->where('site_id', $site))->orderBy('name')->lazy(200)
            ->map(fn (Machine $machine) => [$machine->code, $machine->name, $machine->site?->name, $machine->state, $machine->status]);

        return $this->csv('production-machines.csv', ['Code', 'Machine', 'Site', 'State', 'Status'], $rows);
    }

    public function wastage(Request $request): StreamedResponse
    {
        $this->authorizeExport($request, 'forge.wastage.export');
        $site = SiteLocation::forUser($request->user());
        $rows = WastageRecord::with('workOrder')->whereHas('workOrder', fn ($query) => $query->when($site !== null, fn ($scope) => $scope->where('site_id', $site)))
            ->latest()->lazy(200)
            ->map(fn (WastageRecord $record) => [$record->workOrder?->wo_number, $record->item_name, $record->qty, $record->uom, $record->reason, $record->disposition, $record->created_at?->toDateTimeString()]);

        return $this->csv('production-wastage.csv', ['Work Order', 'Item', 'Quantity', 'UOM', 'Reason', 'Disposition', 'Recorded At'], $rows);
    }

    public function finalQc(Request $request): StreamedResponse
    {
        $this->authorizeExport($request, 'forge.finalqc.export');
        $site = SiteLocation::forUser($request->user());
        $rows = FinalQcResult::with('workOrder.finishedGood')->whereHas('workOrder', fn ($query) => $query->when($site !== null, fn ($scope) => $scope->where('site_id', $site)))
            ->when($request->query('scope') !== 'all' && $request->filled('work_order'), fn ($query) => $query->where('work_order_id', $request->integer('work_order')))
            ->latest()->lazy(200)
            ->map(fn (FinalQcResult $result) => [$result->workOrder?->wo_number, $result->workOrder?->finishedGood?->name, $result->accepted_qty, $result->rejected_qty, $result->leak_test, $result->pressure_test, $result->result]);

        return $this->csv('production-final-qc.csv', ['Work Order', 'Finished Good', 'Accepted', 'Rejected', 'Leak Test', 'Pressure Test', 'Result'], $rows);
    }

    public function batches(Request $request): StreamedResponse
    {
        $this->authorizeExport($request, 'forge.batch.export');
        $site = SiteLocation::forUser($request->user());
        $rows = Batch::with('workOrder.finishedGood')->whereHas('workOrder', fn ($query) => $query->when($site !== null, fn ($scope) => $scope->where('site_id', $site)))
            ->latest()->lazy(200)
            ->map(fn (Batch $batch) => [$batch->batch_number, $batch->workOrder?->wo_number, $batch->workOrder?->finishedGood?->name, $batch->qty, $batch->uom, $batch->status, $batch->shelf_life_date?->toDateString()]);

        return $this->csv('production-batches.csv', ['Batch', 'Work Order', 'Finished Good', 'Quantity', 'UOM', 'Status', 'Shelf Life'], $rows);
    }

    private function authorizeExport(Request $request, string $permission): void
    {
        abort_unless($this->access->can($request->user(), $permission), 403);
    }

    private function csv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $headers, ',', '"', '\\');
            foreach ($rows as $row) {
                fputcsv($stream, array_map($this->csvCell(...), $row), ',', '"', '\\');
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function csvCell(mixed $value): string
    {
        $cell = (string) ($value ?? '');

        return preg_match('/^[=+@\-\t\r]/', $cell) === 1 ? "\t".$cell : $cell;
    }
}
