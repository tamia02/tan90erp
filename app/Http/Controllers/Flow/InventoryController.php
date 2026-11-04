<?php

namespace App\Http\Controllers\Flow;

use App\Http\Controllers\Controller;
use App\Models\Flow\InventoryLot;
use App\Models\Forge\Batch;
use App\Models\Tan90\MasterData\Bin;
use App\Services\Access\AccessControlService;
use App\Services\Flow\FulfillmentService;
use App\Support\SiteLocation;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(
        private AccessControlService $access,
        private FulfillmentService $service,
    ) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'flow.inventory.view'), 403);
        $workOrderId = $request->query('work_order');
        $workOrderId = ctype_digit((string) $workOrderId) ? (int) $workOrderId : null;

        $releasableBatches = Batch::where('status', 'released')
            ->whereDoesntHave('inventoryLot')
            ->when($workOrderId, fn ($q) => $q->where('work_order_id', $workOrderId))
            ->when(SiteLocation::forUser($request->user()), fn ($q, $site) => $q->whereHas('workOrder', fn ($wo) => $wo->where('site_id', $site)))
            ->with('workOrder.finishedGood')->get();

        $this->attachBinSuggestions($releasableBatches);

        $lots = InventoryLot::with(['finishedGood', 'forgeBatch.workOrder'])
            ->when($workOrderId, fn ($q) => $q->whereHas('forgeBatch', fn ($batch) => $batch->where('work_order_id', $workOrderId)))
            ->when(SiteLocation::forUser($request->user()), fn ($q, $site) => $q->where('location_id', $site))
            ->latest()->paginate(20)->withQueryString();
        $this->attachMasterBinSuggestions($lots->getCollection());

        return view('flow.inventory.index', [
            'lots' => $lots,
            'releasableBatches' => $releasableBatches,
            'masterBins' => Bin::with('shelf.rack.zone')->where('status', 'active')->where('approval_status', 'approved')->get(),
            'focusedWorkOrderId' => $workOrderId,
        ]);
    }

    /** Recommend a previously used matching bin, then fall back to an eligible bin in the mapped FG warehouse. */
    private function attachMasterBinSuggestions($lots): void
    {
        $bins = Bin::with('shelf.rack.zone')->where('status', 'active')->where('approval_status', 'approved')->get();
        foreach ($lots as $lot) {
            if ($lot->status !== 'staged' || ! $lot->warehouse_id) {
                continue;
            }
            $eligible = $bins->filter(fn ($bin) => (int) $bin->shelf?->rack?->zone?->tan90_warehouse_id === (int) $lot->warehouse_id);
            $matchingLot = InventoryLot::query()->where('id', '<>', $lot->id)
                ->whereNotNull('bin_id')->where('finished_good_id', $lot->finished_good_id)
                ->where('location_id', $lot->location_id)->where('warehouse_id', $lot->warehouse_id)
                ->where(fn ($q) => $q->where('inventory_item_id', $lot->inventory_item_id)->orWhereNull('inventory_item_id'))
                ->latest('received_at')->latest('id')->first();
            $preferred = $matchingLot?->bin_id
                ? $eligible->first(fn ($bin) => (int) $bin->id === (int) $matchingLot->bin_id)
                : null;
            $preferred ??= $eligible->sortBy('code')->first();
            $lot->setAttribute('suggested_master_bin_id', $preferred?->id);
            $lot->setAttribute('suggested_master_bin_code', $preferred?->code);
        }
    }

    /** Prefill only from a lot with the same FG, site and FG warehouse. */
    private function attachBinSuggestions($batches): void
    {
        if ($batches->isEmpty()) {
            return;
        }

        $lots = InventoryLot::query()
            ->whereNotNull('bin')->where('bin', '<>', '')
            ->where(function ($query) use ($batches) {
                foreach ($batches as $batch) {
                    $wo = $batch->workOrder;
                    if (! $wo) {
                        continue;
                    }

                    $query->orWhere(function ($pair) use ($wo) {
                        $pair->where('finished_good_id', $wo->finished_good_id);
                        $wo->site_id === null ? $pair->whereNull('location_id') : $pair->where('location_id', $wo->site_id);
                        $wo->fg_warehouse_id === null ? $pair->whereNull('warehouse_id') : $pair->where('warehouse_id', $wo->fg_warehouse_id);
                        if ($wo->inventory_fg_item_id !== null) {
                            $pair->where(fn ($item) => $item->where('inventory_item_id', $wo->inventory_fg_item_id)->orWhereNull('inventory_item_id'));
                        } else {
                            $pair->whereNull('inventory_item_id');
                        }
                    });
                }
            })
            ->orderByDesc('received_at')->orderByDesc('id')->get([
                'id', 'finished_good_id', 'inventory_item_id', 'location_id', 'warehouse_id', 'zone', 'bin', 'received_at',
            ]);

        foreach ($batches as $batch) {
            $wo = $batch->workOrder;
            $suggestion = $lots->first(fn ($lot) => $lot->finished_good_id === $wo?->finished_good_id
                && $lot->location_id === $wo?->site_id
                && $lot->warehouse_id === $wo?->fg_warehouse_id
                && ($lot->inventory_item_id === null || $lot->inventory_item_id === $wo?->inventory_fg_item_id));

            $batch->setAttribute('suggested_zone', $suggestion?->zone);
            $batch->setAttribute('suggested_bin', $suggestion?->bin);
        }
    }

    public function receive(Request $request, Batch $batch)
    {
        abort_unless($this->access->can($request->user(), 'flow.inventory.receive'), 403);
        SiteLocation::assertCanAccessSiteRecord($request->user(), $batch->workOrder?->site_id === null ? null : (int) $batch->workOrder->site_id);

        $managed = (int) ($batch->workOrder?->workflow_version ?? 1) >= 2;
        $data = $request->validate([
            'warehouse' => ['nullable', 'string', 'max:255'],
            'zone' => [$managed ? 'nullable' : 'required', 'string', 'max:100'],
            'bin' => [$managed ? 'nullable' : 'required', 'string', 'max:100'],
            'putaway_started_at' => [$managed ? 'nullable' : 'required', 'date'],
            'putaway_ended_at' => [$managed ? 'nullable' : 'required', 'date', 'after_or_equal:putaway_started_at'],
            'putaway_manpower' => [$managed ? 'nullable' : 'required', 'integer', 'min:1'],
        ]);

        $lot = \Illuminate\Support\Facades\DB::transaction(function () use ($batch,$data,$request) {
            $locked=Batch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            return $locked->inventoryLot ?: $this->service->receiveFinishedGoods($locked,$data,$request->user());
        });

        return back()->with('status', "FG lot {$lot->lot_number} received.");
    }

    public function putaway(Request $request, InventoryLot $lot)
    {
        abort_unless($this->access->can($request->user(), 'flow.inventory.putaway'), 403);
        SiteLocation::assertCanAccessSiteRecord($request->user(), $lot->location_id === null ? null : (int) $lot->location_id);

        $managed = (int) ($lot->forgeBatch?->workOrder?->workflow_version ?? 1) >= 2;
        $data = $request->validate([
            'zone' => [$managed ? 'nullable' : 'required', 'string', 'max:100'],
            'bin' => [$managed ? 'nullable' : 'required', 'string', 'max:100'],
            'bin_id' => [$managed ? 'required' : 'nullable', 'exists:tan90_bins,id'],
            'putaway_started_at' => ['required', 'date'],
            'putaway_ended_at' => ['required', 'date', 'after_or_equal:putaway_started_at'],
            'putaway_manpower' => ['required', 'integer', 'min:1'],
        ]);

        $this->service->putaway($lot, $data, $request->user());

        return back()->with('status', "Lot {$lot->lot_number} put away to {$lot->fresh()->bin}.");
    }
}
