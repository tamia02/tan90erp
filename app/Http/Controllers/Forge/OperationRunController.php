<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\{OperationRun, WorkOrder};
use App\Services\Forge\{WorkerProduction, WorkOrderService};
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationRunController extends Controller
{
    public function __construct(private WorkerProduction $workflow, private WorkOrderService $workOrders) {}

    public function start(Request $request, WorkOrder $workOrder, string $operation)
    {
        $definition = WorkerProduction::OPERATIONS[$operation] ?? abort(404);
        $this->workflow->authorize($request->user(), $workOrder, $definition[1]);
        $data = $request->validate(['context_id'=>['nullable','integer','min:0'],'manpower'=>['required','integer','min:1']]);
        DB::transaction(function () use ($request, $workOrder, $operation, $data) {
            $wo = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            $cycle = (int)$wo->rework_generation + 1; $context = $operation === 'materials' ? (int)($data['context_id'] ?? 0) : 0;
            $existing = OperationRun::where('work_order_id',$wo->id)->where('operation',$operation)->where('cycle_no',$cycle)->where('context_id',$context)->first();
            if ($existing) return;
            $this->workflow->assertStartable($wo, $operation, $context);
            if ($operation === 'mixing' && $wo->status === 'material_issued') $this->workOrders->startProgress($wo);
            if ($operation === 'mixing' && $wo->status === 'rework') $this->workOrders->resumeRework($wo,$request->user());
            $wo->update(['workflow_version'=>3]);
            OperationRun::create(['work_order_id'=>$wo->id,'operation'=>$operation,'cycle_no'=>$cycle,'context_id'=>$context,
                'status'=>'in_progress','started_at'=>now(),'started_by'=>$request->user()->id,'payload'=>['manpower'=>$data['manpower']]]);
            AuditLogger::log(ucfirst($operation).' started', $wo->wo_number, $wo);
        });
        return back()->with('status',$definition[2].' started. Complete the work and save the measured quantities.');
    }

    public function saveDraft(Request $request, WorkOrder $workOrder, OperationRun $run)
    {
        abort_unless((int) $run->work_order_id === (int) $workOrder->id && $run->operation === 'bundling', 404);
        $this->workflow->authorize($request->user(), $workOrder, 'forge.bundle.confirm');
        $data = $request->validate([
            'bundle_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'units_per_bundle' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'loose_units' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'defective_qty' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'defective_disposition' => ['sometimes', 'nullable', 'in:scrap,rework,hold'],
            'materials' => ['sometimes', 'array'],
            'materials.*.inventory_item_id' => ['required_with:materials', 'integer', 'distinct', 'exists:inventory_items,id'],
            'materials.*.used_qty' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'materials.*.scrap_qty' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'materials.*.reason' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($workOrder, $run, $data) {
            WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            $locked = OperationRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'in_progress'
                && (int) $locked->cycle_no === (int) $workOrder->fresh()->rework_generation + 1
                && $workOrder->fresh()->status === 'bundle_pending', 409);

            $payload = $locked->payload ?? [];
            $payload['draft'] = array_merge($payload['draft'] ?? [], $data);
            $payload['draft_saved_at'] = now()->toIso8601String();
            $locked->update(['payload' => $payload]);
        });

        return back()->with('status', 'Bundle progress saved. You can return to this work order and continue.');
    }

    public function complete(Request $request, WorkOrder $workOrder, OperationRun $run)
    {
        abort_unless((int)$run->work_order_id === (int)$workOrder->id,404);
        $definition = WorkerProduction::OPERATIONS[$run->operation] ?? abort(404);
        $this->workflow->authorize($request->user(),$workOrder,$definition[1]);
        DB::transaction(function () use ($request,$workOrder,$run) {
            $wo = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            $run = OperationRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status === 'completed') return;
            if ((int)$run->cycle_no !== (int)$wo->rework_generation+1 || $wo->hasOpenHold()) {
                throw ValidationException::withMessages(['status'=>'This run is no longer current or has a quality hold.']);
            }
            $this->workflow->assertStartable($wo,$run->operation,(int)$run->context_id);
            $started = $run->started_at->format('Y-m-d H:i:s'); $ended = now()->format('Y-m-d H:i:s');
            $request->merge(['started_at'=>$started,'ended_at'=>$ended,'handover_started_at'=>$started,'handover_ended_at'=>$ended,
                'putaway_started_at'=>$started,'putaway_ended_at'=>$ended,'manpower'=>$run->payload['manpower'],'putaway_manpower'=>$run->payload['manpower']]);
            $request->attributes->set('worker_operation',$run->operation);
            $recordTable=null; $recordId=null;
            switch ($run->operation) {
                case 'materials':
                    app()->call([app(MaterialRequestController::class),'issue'],['request'=>$request,'workOrder'=>$wo,'materialRequest'=>(int)$run->context_id]);
                    $recordTable='forge_material_requests'; $recordId=$run->context_id; break;
                case 'mixing':
                    if ((int)$run->cycle_no === 1) {
                        app(ProductionOperationController::class)->mixing($request,$wo);
                        $recordTable='forge_mixing_records';
                    } else {
                        $data=$request->validate(['recovered_pcm_qty_handled'=>['required','numeric','gt:0'],'manpower'=>['required','integer','min:1']]);
                        $available=(float)DB::table('forge_final_qc_results')->where('work_order_id',$wo->id)->whereNotNull('released_at')->sum('recovered_pcm_qty')
                            +(float)DB::table('forge_process_records')->where('work_order_id',$wo->id)->sum('recovered_pcm_qty')
                            -(float)DB::table('forge_process_records')->where('work_order_id',$wo->id)->sum('recovered_pcm_used_qty');
                        if ((float)$data['recovered_pcm_qty_handled']>$available+0.0005) throw ValidationException::withMessages(['recovered_pcm_qty_handled'=>'Quantity exceeds recovered PCM available on this order.']);
                        $run->payload=$data;
                    }
                    break;
                case 'filling': case 'sealing':
                    $request->merge(['step'=>$run->operation]);
                    app(ProductionOperationController::class)->process($request,$wo);
                    $recordTable='forge_process_records';
                    if ($run->operation === 'sealing') {
                        $this->workOrders->recordProduction($wo->fresh(),['good_qty'=>$request->input('good_qty'),'rejected_qty'=>$request->input('rejected_qty',0),'rework_qty'=>0],$request->user());
                    }
                    break;
                case 'quality':
                    app(FinalQcController::class)->store($request,$wo); $recordTable='forge_final_qc_results'; break;
                case 'bundling':
                    app()->call([app(BundleController::class),'confirm'],['request'=>$request,'workOrder'=>$wo]); $recordTable='forge_bundle_records'; break;
                case 'putaway':
                    app(\App\Http\Controllers\Flow\InventoryController::class)->putaway($request,$wo->batch->inventoryLot);
                    $recordTable='flow_inventory_lots'; $recordId=$wo->batch->inventoryLot->id;
                    $this->workOrders->close($wo->fresh()); break;
            }
            if ($recordTable && !$recordId) $recordId=DB::table($recordTable)->where('work_order_id',$wo->id)->latest('id')->value('id');
            $payload = $run->payload ?? [];
            unset($payload['draft'], $payload['draft_saved_at']);
            $run->update(['status'=>'completed','ended_at'=>$ended,'completed_by'=>$request->user()->id,
                'payload'=>array_merge($payload,['record_table'=>$recordTable,'record_id'=>$recordId])]);
            AuditLogger::log(ucfirst($run->operation).' completed', $wo->wo_number, $run);
        });
        $next=match($run->operation){'materials'=>'PCM Making · Mixing Operator','mixing'=>'Filling · Production Operator','filling'=>'Sealing · Production Operator',
            'sealing'=>'Production Approval · Production Manager','quality'=>'QC Approval · Quality Manager','bundling'=>'FG Store · FG Receiver','putaway'=>'Packaging & Dispatch · Logistics'};
        return redirect()->route('forge.modules.show',['step'=>$definition[0]])->with('status',$definition[2].' completed. Next: '.$next.'.');
    }

    public function approveOutput(Request $request, WorkOrder $workOrder)
    {
        $this->workflow->authorize($request->user(),$workOrder,'forge.production.approve');
        DB::transaction(function () use ($request,$workOrder) {
            $wo=WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            if ($wo->status==='final_qc_pending') return;
            $entries=$wo->productionEntries()->where('cycle_no',(int)$wo->rework_generation+1)->where('status','draft')->get();
            if($entries->isEmpty()) throw ValidationException::withMessages(['status'=>'Complete sealing before approving production.']);
            foreach($entries as $entry) $this->workOrders->approveProduction($entry,$request->user());
            $this->workOrders->sendToFinalQc($wo->fresh());
        });
        return redirect()->route('forge.modules.show',['step'=>'filling-sealing'])->with('status','Production approved and sent to QC.');
    }

    public function correct(Request $request, WorkOrder $workOrder, OperationRun $run)
    {
        abort_unless((int)$run->work_order_id === (int)$workOrder->id,404);
        $this->workflow->authorize($request->user(),$workOrder,'forge.operation.correct');
        $data=$request->validate(['started_at'=>['required','date','before_or_equal:now'],'ended_at'=>['required','date','after_or_equal:started_at','before_or_equal:now'],'reason'=>['required','string','min:5','max:1000']]);
        DB::transaction(function () use ($run,$data,$workOrder) {
            WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            $locked=OperationRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if($locked->status!=='completed') throw ValidationException::withMessages(['status'=>'Correct a completed run only.']);
            if($locked->operation!=='materials') {
                $rank=['mixing'=>1,'filling'=>2,'sealing'=>3,'quality'=>4,'bundling'=>5,'putaway'=>6];
                $key=(int)$locked->cycle_no*10+$rank[$locked->operation];
                $others=OperationRun::where('work_order_id',$workOrder->id)->where('id','<>',$locked->id)->where('operation','<>','materials')->get();
                $previous=$others->filter(fn($r)=>(int)$r->cycle_no*10+$rank[$r->operation]<$key)->filter(fn($r)=>$r->ended_at)->max('ended_at');
                $next=$others->filter(fn($r)=>(int)$r->cycle_no*10+$rank[$r->operation]>$key)->min('started_at');
                if(($previous && \Illuminate\Support\Carbon::parse($data['started_at'])->lt($previous)) || ($next && \Illuminate\Support\Carbon::parse($data['ended_at'])->gt($next))) {
                    throw ValidationException::withMessages(['started_at'=>'Corrected times cannot overlap preceding or following production operations.']);
                }
            }
            $before=['started_at'=>$locked->started_at->toDateTimeString(),'ended_at'=>$locked->ended_at->toDateTimeString()];
            $locked->update(['started_at'=>$data['started_at'],'ended_at'=>$data['ended_at']]);
            $payload=$locked->payload??[]; $table=$payload['record_table']??null;
            $columns=match($table){'forge_material_requests'=>['handover_started_at','handover_ended_at'],'flow_inventory_lots'=>['putaway_started_at','putaway_ended_at'],
                'forge_mixing_records','forge_process_records','forge_final_qc_results','forge_bundle_records'=>['started_at','ended_at'],default=>null};
            if($columns && ($payload['record_id']??null)) DB::table($table)->where('id',$payload['record_id'])->update([$columns[0]=>$data['started_at'],$columns[1]=>$data['ended_at']]);
            if($table==='forge_material_requests' && (int)DB::table('forge_material_requests')->where('work_order_id',$workOrder->id)->where('status','issued')->latest('id')->value('id')===(int)$payload['record_id']) {
                $workOrder->update(['material_handover_started_at'=>$data['started_at'],'material_handover_ended_at'=>$data['ended_at']]);
            }
            if($table==='forge_process_records') DB::table('forge_machine_run_intervals')->where('process_record_id',$payload['record_id'])->update(['started_at'=>$data['started_at'],'ended_at'=>$data['ended_at']]);
            AuditLogger::log('Production time corrected',json_encode(['wo'=>$workOrder->wo_number,'run'=>$locked->id,'before'=>$before,'after'=>$data]),$locked);
        });
        return back()->with('status','Time correction saved with its audit reason.');
    }
}
