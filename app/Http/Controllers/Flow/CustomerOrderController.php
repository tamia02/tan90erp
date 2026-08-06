<?php

namespace App\Http\Controllers\Flow;

use App\Http\Controllers\Controller;
use App\Models\Flow\CustomerOrder;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Services\Access\AccessControlService;
use App\Services\Flow\FulfillmentService;
use Illuminate\Http\Request;

class CustomerOrderController extends Controller
{
    public function __construct(
        private AccessControlService $access,
        private FulfillmentService $service,
    ) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'flow.order.view'), 403);

        return view('flow.orders.index', [
            'orders' => CustomerOrder::withCount('lines')->latest()->paginate(20),
        ]);
    }

    public function show(Request $request, CustomerOrder $order)
    {
        abort_unless($this->access->can($request->user(), 'flow.order.view'), 403);

        $order->load(['lines.finishedGood', 'lines.allocations.inventoryLot', 'handlingUnits.shipment', 'returns']);

        return view('flow.orders.show', [
            'order' => $order,
            'finishedGoods' => FinishedGood::active()->approvalStatus('approved')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'flow.order.create'), 403);

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'destination' => ['nullable', 'string', 'max:255'],
            'temperature_requirement' => ['nullable', 'in:ambient,chilled,frozen'],
            'min_shelf_life_days' => ['nullable', 'integer', 'min:0'],
            'requested_date' => ['nullable', 'date'],
        ]);

        $order = $this->service->createOrder($data, $request->user());

        return redirect()->route('flow.orders.show', $order)->with('status', "Order {$order->order_number} created.");
    }

    public function addLine(Request $request, CustomerOrder $order)
    {
        abort_unless($this->access->can($request->user(), 'flow.order.create'), 403);

        $data = $request->validate([
            'finished_good_id' => ['required', 'exists:tan90_finished_goods,id'],
            'qty_ordered' => ['required', 'numeric', 'min:0.001'],
            'uom' => ['required', 'string', 'max:20'],
        ]);

        $this->service->addLine($order, $data);

        return back()->with('status', 'Line added.');
    }

    public function validateOrder(Request $request, CustomerOrder $order)
    {
        abort_unless($this->access->can($request->user(), 'flow.order.release'), 403);
        $this->service->validateOrder($order);

        return back()->with('status', "Order {$order->order_number} validated.");
    }

    public function release(Request $request, CustomerOrder $order)
    {
        abort_unless($this->access->can($request->user(), 'flow.order.release'), 403);
        $this->service->release($order);

        return back()->with('status', "Order {$order->order_number} released — status now {$order->fresh()->status}.");
    }
}
