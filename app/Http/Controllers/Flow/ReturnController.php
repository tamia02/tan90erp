<?php

namespace App\Http\Controllers\Flow;

use App\Http\Controllers\Controller;
use App\Models\Flow\CustomerOrder;
use App\Models\Flow\ReturnRequest;
use App\Services\Access\AccessControlService;
use App\Services\Flow\FulfillmentService;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    public function __construct(
        private AccessControlService $access,
        private FulfillmentService $service,
    ) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'flow.return.manage'), 403);

        return view('flow.returns.index', [
            'returns' => ReturnRequest::with('order')->latest()->paginate(20),
            'closedOrders' => CustomerOrder::whereIn('status', ['closed', 'delivered'])->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'flow.return.manage'), 403);

        $data = $request->validate([
            'customer_order_id' => ['required', 'exists:flow_customer_orders,id'],
            'reason' => ['required', 'string', 'max:255'],
            'qty' => ['required', 'numeric', 'min:0.001'],
            'uom' => ['required', 'string', 'max:20'],
        ]);

        $return = $this->service->requestReturn($data, $request->user());

        return back()->with('status', "RMA {$return->rma_number} requested.");
    }

    public function inspect(Request $request, ReturnRequest $return)
    {
        abort_unless($this->access->can($request->user(), 'flow.return.manage'), 403);

        $data = $request->validate([
            'disposition' => ['required', 'in:restock,rework,scrap,reject'],
            'inspection_notes' => ['nullable', 'string', 'max:2000'],
            'claim_raised' => ['nullable', 'boolean'],
            'claim_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $data['claim_status'] = ($data['claim_raised'] ?? false) ? 'pending' : null;

        $this->service->inspectReturn($return, $data, $request->user());

        return back()->with('status', "RMA {$return->rma_number} dispositioned.");
    }
}
