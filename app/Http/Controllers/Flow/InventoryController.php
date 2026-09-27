<?php

namespace App\Http\Controllers\Flow;

use App\Http\Controllers\Controller;
use App\Models\Flow\InventoryLot;
use App\Models\Forge\Batch;
use App\Services\Access\AccessControlService;
use App\Services\Flow\FulfillmentService;
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

        return view('flow.inventory.index', [
            'lots' => InventoryLot::with('finishedGood')->latest()->paginate(20),
            'releasableBatches' => Batch::where('status', 'released')
                ->whereDoesntHave('inventoryLot')
                ->with('workOrder.finishedGood')->get(),
        ]);
    }

    public function receive(Request $request, Batch $batch)
    {
        abort_unless($this->access->can($request->user(), 'flow.inventory.receive'), 403);

        $data = $request->validate([
            'warehouse' => ['nullable', 'string', 'max:255'],
            'zone' => ['nullable', 'string', 'max:100'],
            'bin' => ['nullable', 'string', 'max:100'],
        ]);

        $lot = $this->service->receiveFinishedGoods($batch, $data, $request->user());

        return back()->with('status', "FG lot {$lot->lot_number} received.");
    }

    public function putaway(Request $request, InventoryLot $lot)
    {
        abort_unless($this->access->can($request->user(), 'flow.inventory.putaway'), 403);

        $data = $request->validate([
            'zone' => ['required', 'string', 'max:100'],
            'bin' => ['required', 'string', 'max:100'],
        ]);

        $this->service->putaway($lot, $data['zone'], $data['bin'], $request->user());

        return back()->with('status', "Lot {$lot->lot_number} put away to {$data['bin']}.");
    }
}
