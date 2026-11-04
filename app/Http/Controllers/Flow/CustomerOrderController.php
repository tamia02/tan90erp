<?php

namespace App\Http\Controllers\Flow;

use App\Http\Controllers\Controller;
use App\Models\Flow\CustomerOrder;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Models\Tan90\MasterData\Warehouse;
use App\Services\Forge\SalesProductionService;
use App\Support\SiteLocation;
use App\Services\Access\AccessControlService;
use App\Services\Flow\FulfillmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            'orders' => CustomerOrder::withCount('lines')->when(SiteLocation::forUser($request->user()), fn ($q, $site) => $q->where('site_id', $site))->latest()->paginate(20),
            'locations' => SiteLocation::visibleLocations(),
            'warehouses' => Warehouse::where('status', 'active')->where('warehouse_type', 'Finished Goods')
                ->when(SiteLocation::forUser($request->user()) !== null, fn ($q) => $q->where('tan90_location_id', SiteLocation::forUser($request->user())))
                ->orderBy('code')->get(),
        ]);
    }

    public function show(Request $request, CustomerOrder $order)
    {
        abort_unless($this->access->can($request->user(), 'flow.order.view'), 403);
        SiteLocation::assertCanAccessSiteRecord($request->user(), $order->site_id === null ? null : (int) $order->site_id);

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
            'site_id' => ['required', 'exists:tan90_locations,id'],
            'fg_warehouse_id' => ['required', 'exists:tan90_warehouses,id'],
        ]);
        SiteLocation::assertCanAccessSiteRecord($request->user(), (int) $data['site_id']);
        if (! Warehouse::whereKey($data['fg_warehouse_id'])->where('tan90_location_id', $data['site_id'])->where('status', 'active')->where('warehouse_type', 'Finished Goods')->exists()) {
            throw ValidationException::withMessages(['fg_warehouse_id' => 'Choose an active finished-goods warehouse assigned to the selected site.']);
        }

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
        SiteLocation::assertCanAccessSiteRecord($request->user(), $order->site_id === null ? null : (int) $order->site_id);
        if ($order->site_id) {
            $mapping = app(SalesProductionService::class)->approvedMapping((int) $order->site_id, (int) $data['finished_good_id'], (int) $order->fg_warehouse_id);
            $item = \App\Models\Inventory\InventoryItem::findOrFail($mapping->inventory_fg_item_id);
            if ($data['uom'] !== $item->unit) {
                throw \Illuminate\Validation\ValidationException::withMessages(['uom' => 'UOM must match mapped Inventory FG SKU: '.$item->unit]);
            }
            $data['inventory_fg_item_id'] = $item->id;
        }

        $this->service->addLine($order, $data);

        return back()->with('status', 'Line added.');
    }

    public function validateOrder(Request $request, CustomerOrder $order)
    {
        abort_unless($this->access->can($request->user(), 'flow.order.release'), 403);
        SiteLocation::assertCanAccessSiteRecord($request->user(), $order->site_id === null ? null : (int) $order->site_id);
        $this->service->validateOrder($order);

        return back()->with('status', "Order {$order->order_number} validated.");
    }

    public function release(Request $request, CustomerOrder $order)
    {
        abort_unless($this->access->can($request->user(), 'flow.order.release'), 403);
        SiteLocation::assertCanAccessSiteRecord($request->user(), $order->site_id === null ? null : (int) $order->site_id);
        $this->service->release($order);

        return back()->with('status', "Order {$order->order_number} released — status now {$order->fresh()->status}.");
    }
}
