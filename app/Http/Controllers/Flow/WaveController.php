<?php

namespace App\Http\Controllers\Flow;

use App\Http\Controllers\Controller;
use App\Models\Flow\Allocation;
use App\Models\Flow\PickingWave;
use App\Models\Flow\PickTask;
use App\Services\Access\AccessControlService;
use App\Services\Flow\FulfillmentService;
use Illuminate\Http\Request;

class WaveController extends Controller
{
    public function __construct(
        private AccessControlService $access,
        private FulfillmentService $service,
    ) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'flow.wave.manage'), 403);

        return view('flow.waves.index', [
            'waves' => PickingWave::with('pickTasks.allocation.orderLine.finishedGood', 'pickTasks.allocation.orderLine.order')->latest()->paginate(20),
            'reservedAllocations' => Allocation::with('orderLine.finishedGood', 'orderLine.order')->where('status', 'reserved')
                ->whereDoesntHave('pickTask')->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'flow.wave.manage'), 403);

        $data = $request->validate([
            'order_line_ids' => ['required', 'array', 'min:1'],
            'order_line_ids.*' => ['exists:flow_order_lines,id'],
            'warehouse' => ['nullable', 'string', 'max:255'],
        ]);

        $wave = $this->service->buildWave($data['order_line_ids'], $data['warehouse'] ?? 'Bhiwandi FG Warehouse', $request->user());

        return redirect()->route('flow.waves.index')->with('status', "Wave {$wave->wave_number} built.");
    }

    public function publish(Request $request, PickingWave $wave)
    {
        abort_unless($this->access->can($request->user(), 'flow.wave.manage'), 403);
        $this->service->publishWave($wave, $request->user());

        return back()->with('status', "Wave {$wave->wave_number} published.");
    }

    public function confirmPick(Request $request, PickTask $pickTask)
    {
        abort_unless($this->access->can($request->user(), 'flow.pick.confirm'), 403);

        $data = $request->validate(['qty_picked' => ['required', 'numeric', 'min:0']]);
        $this->service->confirmPick($pickTask, $data['qty_picked'], $request->user());

        return back()->with('status', 'Pick confirmed.');
    }
}
