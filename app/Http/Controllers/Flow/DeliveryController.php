<?php

namespace App\Http\Controllers\Flow;

use App\Http\Controllers\Controller;
use App\Models\Flow\Delivery;
use App\Models\Flow\Shipment;
use App\Services\Access\AccessControlService;
use App\Services\Flow\FulfillmentService;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    public function __construct(
        private AccessControlService $access,
        private FulfillmentService $service,
    ) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'flow.delivery.manage'), 403);

        return view('flow.deliveries.index', [
            'deliveries' => Delivery::with('order', 'shipment')->latest()->paginate(20),
            'inTransitShipments' => Shipment::where('status', 'in_transit')->with('handlingUnits.order')->get(),
        ]);
    }

    public function store(Request $request, Shipment $shipment)
    {
        abort_unless($this->access->can($request->user(), 'flow.delivery.manage'), 403);

        $data = $request->validate([
            'customer_order_id' => ['required', 'exists:flow_customer_orders,id'],
            'receiver_name' => ['nullable', 'string', 'max:255'],
            'qty_accepted' => ['nullable', 'numeric', 'min:0'],
            'exception_notes' => ['nullable', 'string', 'max:2000'],
            'pod_reference' => ['required', 'string', 'max:255'],
        ]);

        $order = \App\Models\Flow\CustomerOrder::findOrFail($data['customer_order_id']);
        $data['delivered_at'] = now();
        unset($data['customer_order_id']);

        $delivery = $this->service->recordDelivery($shipment, $order, $data, $request->user());

        return back()->with('status', "POD recorded for {$order->order_number}.");
    }

    public function close(Request $request, Delivery $delivery)
    {
        abort_unless($this->access->can($request->user(), 'flow.delivery.manage'), 403);
        $this->service->closeDelivery($delivery, $request->user());

        return back()->with('status', 'Delivery closed.');
    }
}
