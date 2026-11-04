<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\WorkOrder;
use App\Models\Inventory\InventoryItem;
use App\Services\Access\AccessControlService;
use App\Services\Forge\MaterialRequestService;
use App\Services\Forge\ProductionTaskService;
use App\Support\SiteLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionOperationController extends Controller
{
    public function __construct(private AccessControlService $access) {}

    public function mixing(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeOperation($request, $workOrder);
        $data = $request->validate([
            'mixing_type' => ['required', 'in:manual,mixer'],
            'started_at' => ['required', 'date'], 'ended_at' => ['required', 'date', 'after_or_equal:started_at'],
            'chemical_qty' => ['required', 'numeric', 'min:0'], 'water_qty' => ['required', 'numeric', 'min:0'],
            'pcm_qty' => ['required', 'numeric', 'gt:0'], 'wastage_qty' => ['nullable', 'numeric', 'min:0'],
            'wastage_type' => ['nullable', 'in:liquid,solid'],
            'pcm_uom' => ['required', 'string', 'max:20'],
            'time_to_machine_minutes' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'manpower' => ['required', 'integer', 'min:1'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['wastage_qty'] = $data['wastage_qty'] ?? 0;
        if ((float) ($data['wastage_qty'] ?? 0) > 0 && empty($data['wastage_type'])) {
            throw ValidationException::withMessages(['wastage_type' => 'Choose whether mixing wastage is liquid or solid.']);
        }
        DB::transaction(function () use ($data, $workOrder, $request): void {
            WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            if (DB::table('forge_mixing_records')->where('work_order_id', $workOrder->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'This work order already has its single PCM mix. Add only a same-order QC recovery during rework; do not create a second mix.']);
            }
            if ($workOrder->reusable_pcm_item_id) {
                $item = InventoryItem::findOrFail($workOrder->reusable_pcm_item_id);
                if (strtoupper($item->unit) !== strtoupper($data['pcm_uom'])) {
                    throw ValidationException::withMessages(['pcm_uom' => 'Use the PCM unit from its linked recipe item ('.$item->unit.').']);
                }
            }
            DB::table('forge_mixing_records')->insert($data + [
                'work_order_id' => $workOrder->id, 'recorded_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        if ((int) $workOrder->workflow_version >= 2) {
            app(ProductionTaskService::class)->done('wo:'.$workOrder->id.':mixing', $request->user()->id);
            app(ProductionTaskService::class)->open('wo:'.$workOrder->id.':production', 'Record filling and sealing for '.$workOrder->wo_number,
                'forge.production.record', (int) $workOrder->site_id, $request->user()->id, $workOrder);
        }

        return back()->with('status', ucfirst($data['mixing_type']).' PCM mixing recorded.');
    }

    public function process(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeOperation($request, $workOrder);
        $data = $request->validate([
            'step' => ['required', 'in:filling,sealing'],
            'machine_id' => [(int)$workOrder->workflow_version>=3?'required_if:machine_type,automatic':'nullable', 'nullable', 'exists:forge_machines,id'],
            'machine_type' => ['required', 'in:manual,automatic'],
            'started_at' => ['required', 'date'], 'ended_at' => ['required', 'date', 'after_or_equal:started_at'],
            'good_qty' => ['required', 'numeric', 'min:0'], 'rejected_qty' => ['nullable', 'numeric', 'min:0'],
            'wastage_qty' => ['nullable', 'numeric', 'min:0'], 'recovered_pcm_qty' => ['nullable', 'numeric', 'min:0'],
            'manpower' => ['required', 'integer', 'min:1'],
            'continuous_feed_qty' => ['nullable', 'numeric', 'gt:0'], 'power_kwh' => ['nullable', 'numeric', 'min:0'],
            'recovered_pcm_used_qty' => ['nullable', 'numeric', 'gt:0'],
            'idle_minutes' => ['nullable', 'integer', 'min:0', 'max:65535'], 'idle_reason' => ['nullable', 'string', 'max:255'],
            'breakdown_minutes' => ['nullable', 'integer', 'min:0', 'max:65535'], 'breakdown_reason' => ['nullable', 'string', 'max:255'],
            'wastage_reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        foreach ([
            'idle_minutes' => ['idle_reason', 'Explain why the line was idle.'],
            'breakdown_minutes' => ['breakdown_reason', 'Add the breakdown reason.'],
            'wastage_qty' => ['wastage_reason', 'Explain what caused the production wastage.'],
        ] as $quantityField => [$reasonField, $message]) {
            if ((float) ($data[$quantityField] ?? 0) > 0 && empty($data[$reasonField])) {
                throw ValidationException::withMessages([$reasonField => $message]);
            }
        }
        if ((float) ($data['recovered_pcm_qty'] ?? 0) > (float) ($data['wastage_qty'] ?? 0) + 0.0005) {
            throw ValidationException::withMessages(['recovered_pcm_qty' => 'Recovered PCM cannot exceed the wastage recorded for this operation.']);
        }
        if ($data['step'] === 'filling' && ! DB::table('forge_mixing_records')->where('work_order_id', $workOrder->id)->exists()) {
            throw ValidationException::withMessages(['step' => 'Record PCM mixing before filling.']);
        }
        if (!empty($data['machine_id'])) {
            $machine = \App\Models\Forge\Machine::findOrFail($data['machine_id']);
            if ($machine->site_id === null || (int) $machine->site_id !== (int) $workOrder->site_id || ((int)$workOrder->workflow_version>=3 && $machine->status!=='active')) {
                throw ValidationException::withMessages(['machine_id' => 'Choose a machine assigned to this work order site.']);
            }
        }
        $cycleNo = (int) $workOrder->rework_generation + 1;
        if ($data['step'] === 'sealing' && ! DB::table('forge_process_records')->where('work_order_id', $workOrder->id)->where('cycle_no', $cycleNo)->where('step', 'filling')->exists()) {
            throw ValidationException::withMessages(['step' => 'Record filling before sealing.']);
        }
        if ($data['step'] === 'filling') {
            $hasFeed = DB::table('forge_process_records')->where('work_order_id', $workOrder->id)->whereNotNull('continuous_feed_qty')->exists();
            if (! $hasFeed && empty($data['continuous_feed_qty'])) {
                throw ValidationException::withMessages(['continuous_feed_qty' => 'Record the single continuous PCM feed on the first filling run.']);
            }
            if ($hasFeed && ! empty($data['continuous_feed_qty'])) {
                throw ValidationException::withMessages(['continuous_feed_qty' => 'This work order already has its continuous PCM feed entry. Record pauses as idle or breakdown time; do not split the feed into pours.']);
            }
            if (! empty($data['continuous_feed_qty'])) {
                $mix = DB::table('forge_mixing_records')->where('work_order_id', $workOrder->id)->first();
                $recovered = (float) DB::table('forge_final_qc_results')->where('work_order_id', $workOrder->id)->whereNotNull('released_at')->sum('recovered_pcm_qty');
                $mixingRecovery = $mix->wastage_type === 'liquid' ? (float) $mix->wastage_qty : 0.0;
                if ((float) $data['continuous_feed_qty'] > (float) $mix->pcm_qty + $mixingRecovery + $recovered + 0.0005) {
                    throw ValidationException::withMessages(['continuous_feed_qty' => 'Continuous feed exceeds solution prepared plus PCM recovered earlier in this same work order.']);
                }
            }
            $recoveredAvailable = (float) DB::table('forge_final_qc_results')->where('work_order_id', $workOrder->id)
                ->whereNotNull('released_at')->sum('recovered_pcm_qty')
                + (float) DB::table('forge_process_records')->where('work_order_id', $workOrder->id)->sum('recovered_pcm_qty')
                - (float) DB::table('forge_process_records')->where('work_order_id', $workOrder->id)->sum('recovered_pcm_used_qty');
            if ($recoveredAvailable > 0.0005 && (float) ($data['recovered_pcm_used_qty'] ?? 0) <= 0) {
                throw ValidationException::withMessages(['recovered_pcm_used_qty' => 'Record the recovered PCM reused in this same work order before continuing filling.']);
            }
            if ((float) ($data['recovered_pcm_used_qty'] ?? 0) > $recoveredAvailable + 0.0005) {
                throw ValidationException::withMessages(['recovered_pcm_used_qty' => 'Recovered PCM reuse exceeds the quantity available from this work order QC history.']);
            }
        }
        foreach (['rejected_qty', 'wastage_qty', 'recovered_pcm_qty', 'recovered_pcm_used_qty', 'idle_minutes', 'breakdown_minutes'] as $quantityField) {
            $data[$quantityField] = $data[$quantityField] ?? 0;
        }
        if ($data['step'] === 'sealing') {
            $filled = (float) DB::table('forge_process_records')->where('work_order_id', $workOrder->id)->where('cycle_no', $cycleNo)->where('step', 'filling')->sum('good_qty');
            $sealed = (float) DB::table('forge_process_records')->where('work_order_id', $workOrder->id)->where('cycle_no', $cycleNo)->where('step', 'sealing')->sum('good_qty');
            if ($sealed + (float) $data['good_qty'] + ((int)$workOrder->workflow_version>=3?(float)($data['rejected_qty']??0):0) > $filled + 0.0005) {
                throw ValidationException::withMessages(['good_qty' => 'Sealing output exceeds recorded filling output.']);
            }
        }
        if ((int) $workOrder->workflow_version >= 2 && $data['step'] === 'filling') {
            $filled = (float) DB::table('forge_process_records')->where('work_order_id', $workOrder->id)->where('cycle_no', $cycleNo)->where('step', 'filling')->sum('good_qty');
            $coverage = app(MaterialRequestService::class)->maxSupportedOutput($workOrder);
            $target=(float)$workOrder->target_qty;
            if((int)$workOrder->workflow_version>=3 && $cycleNo>1) $target=(float)DB::table('forge_final_qc_results')->where('work_order_id',$workOrder->id)->where('cycle_no',$cycleNo-1)->whereNotNull('released_at')->latest('id')->value('cycle_rework_qty');
            if ($filled + (float) $data['good_qty'] + ((int)$workOrder->workflow_version>=3?(float)($data['rejected_qty']??0):0) > min($target, $coverage) + 0.0005) {
                throw ValidationException::withMessages(['good_qty' => 'Filling exceeds issued BOM material coverage or work order target.']);
            }
        }
        DB::transaction(function () use ($data, $workOrder, $request, $cycleNo): void {
            $recordedAt = now();
            $data['cycle_no'] = $cycleNo;
            $processRecordId = DB::table('forge_process_records')->insertGetId($data + [
                'work_order_id' => $workOrder->id, 'recorded_by' => $request->user()->id,
                'created_at' => $recordedAt, 'updated_at' => $recordedAt,
            ]);

            if (($data['machine_id'] ?? null) !== null) {
                DB::table('forge_machine_run_intervals')->insert([
                    'machine_id' => $data['machine_id'], 'process_record_id' => $processRecordId,
                    'started_at' => $data['started_at'], 'ended_at' => $data['ended_at'],
                    'created_at' => $recordedAt, 'updated_at' => $recordedAt,
                ]);
            }
        });

        return back()->with('status', ucfirst($data['step']).' recorded.');
    }

    private function authorizeOperation(Request $request, WorkOrder $wo): void
    {
        $operation=$request->attributes->get('worker_operation');
        $permission=$operation ? \App\Services\Forge\WorkerProduction::OPERATIONS[$operation][1] : 'forge.production.record';
        abort_unless($this->access->can($request->user(), $permission), 403);
        $site = SiteLocation::forUser($request->user());
        abort_if($site !== null && ($wo->site_id === null || (int) $wo->site_id !== $site), 403);
        if (! $wo->inventory_fg_item_id || ! in_array($wo->status, ['in_progress', 'reconciliation'], true)) {
            throw ValidationException::withMessages(['status' => 'Production entries are available only after this mapped work order has started. Issue material and start the work order first.']);
        }
        if ($wo->hasOpenHold()) {
            throw ValidationException::withMessages(['status' => 'Resolve the quality hold before recording production.']);
        }
    }
}
