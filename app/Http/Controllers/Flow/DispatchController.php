<?php

namespace App\Http\Controllers\Flow;

use App\Http\Controllers\Controller;
use App\Models\Flow\HandlingUnit;
use App\Models\Flow\Shipment;
use App\Models\Flow\TemperatureEvent;
use App\Services\Access\AccessControlService;
use App\Services\Flow\FulfillmentService;
use Illuminate\Http\Request;

class DispatchController extends Controller
{
    public function __construct(
        private AccessControlService $access,
        private FulfillmentService $service,
    ) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'flow.dispatch.manage'), 403);

        return view('flow.dispatch.index', [
            'shipments' => Shipment::with('handlingUnits.order', 'temperatureEvents')->latest()->paginate(20),
            'sealedUnits' => HandlingUnit::where('status', 'sealed')->whereNull('shipment_id')->with('order')->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'flow.dispatch.manage'), 403);

        $data = $request->validate([
            'warehouse' => ['nullable', 'string', 'max:255'],
            'dock_number' => ['nullable', 'string', 'max:100'],
            'transporter' => ['nullable', 'string', 'max:255'],
            'vehicle_number' => ['nullable', 'string', 'max:100'],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'temperature_logger_id' => ['nullable', 'string', 'max:100'],
            'seal_number' => ['nullable', 'string', 'max:100'],
        ]);

        $shipment = $this->service->createShipment($data);

        return redirect()->route('flow.dispatch.index')->with('status', "Shipment {$shipment->shipment_number} created.");
    }

    public function loadUnit(Request $request, Shipment $shipment, HandlingUnit $handlingUnit)
    {
        abort_unless($this->access->can($request->user(), 'flow.dispatch.manage'), 403);
        $this->service->loadHandlingUnit($shipment, $handlingUnit);

        return back()->with('status', "{$handlingUnit->hu_number} loaded onto {$shipment->shipment_number}.");
    }

    public function release(Request $request, Shipment $shipment)
    {
        abort_unless($this->access->can($request->user(), 'flow.dispatch.manage'), 403);
        $this->service->releaseShipment($shipment, $request->user());

        return back()->with('status', "Shipment {$shipment->shipment_number} released.");
    }

    public function recordTemperature(Request $request, Shipment $shipment)
    {
        abort_unless($this->access->can($request->user(), 'flow.dispatch.manage'), 403);

        $data = $request->validate([
            'reading_celsius' => ['required', 'numeric'],
            'excursion' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->service->recordTemperature($shipment, $data, $request->user());

        return back()->with('status', 'Temperature reading recorded.');
    }

    public function dispositionExcursion(Request $request, TemperatureEvent $temperatureEvent)
    {
        abort_unless($this->access->can($request->user(), 'flow.dispatch.manage'), 403);

        $data = $request->validate(['disposition' => ['required', 'in:release,customer_deviation,return_to_warehouse']]);
        $this->service->dispositionExcursion($temperatureEvent, $data['disposition']);

        return back()->with('status', 'Excursion dispositioned.');
    }
}
