<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\{WorkOrder, ProductionPlan, ProductionFgMapping};
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Models\Inventory\InventoryItem;
use App\Models\Tan90\MasterData\Bin;
use App\Services\Access\AccessControlService;
use App\Services\Forge\{WorkerProduction, DirectWorkOrderService};
use App\Services\Inventory\InventoryService;
use App\Support\SiteLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkerProductionController extends Controller
{
    public function __construct(private WorkerProduction $workflow, private AccessControlService $access, private DirectWorkOrderService $direct) {}

    public function orders(Request $request)
    {
        abort_unless($this->access->can($request->user(),'forge.workorder.view'),403);
        if($request->filled('plan')) return redirect()->route('forge.workorders.create',['plan'=>$request->query('plan')]);
        if(isset(WorkerProduction::STEPS[$request->query('phase','')])) return redirect()->route('forge.modules.show',['step'=>$request->query('phase')]);
        $history=$request->routeIs('forge.workorders.history');
        $q=WorkOrder::with(['finishedGood','site'])->when(SiteLocation::forUser($request->user()),fn($q,$id)=>$q->where('site_id',$id));
        $history ? $q->whereIn('status',['closed','rejected']) : $q->whereNotIn('status',['closed','rejected']);
        $search=trim((string)$request->query('search',''));
        $q->when($search!== '',fn($q)=>$q->where(fn($s)=>$s->where('wo_number','like','%'.$search.'%')->orWhereHas('finishedGood',fn($f)=>$f->where('name','like','%'.$search.'%'))));
        return view('forge.worker.orders',['orders'=>$q->latest('id')->paginate(20)->withQueryString(),'history'=>$history,
            'canExport'=>$this->access->can($request->user(),'forge.workorder.export'),
            'canCreate'=>$this->access->can($request->user(),'forge.workorder.create')]);
    }

    public function create(Request $request)
    {
        abort_unless($this->access->can($request->user(),'forge.workorder.create'),403);
        $locations=SiteLocation::visibleLocations(); $eligible=[]; $setup=[];
        $mappings=ProductionFgMapping::where('status','approved')->whereIn('site_id',$locations->pluck('id'))->get();
        $goods=FinishedGood::where('status','active')->where('approval_status','approved')->orderBy('name')->get();
        foreach($mappings as $m) {
            $fg=$goods->firstWhere('id',$m->finished_good_id); if(!$fg) continue;
            try { app(\App\Services\Forge\SalesProductionService::class)->approvedMapping((int)$m->site_id,(int)$fg->id); }
            catch(\Illuminate\Validation\ValidationException $e) { $setup[]=$fg->name; continue; }
            $definitions=$this->direct->definitions($fg->id); if($definitions->isEmpty()) {$setup[]=$fg->name;continue;}
            $eligible[]=['site_id'=>$m->site_id,'fg_id'=>$fg->id,'name'=>$fg->code.' · '.$fg->name,'unit'=>$fg->uom,'definitions'=>$definitions->values()->all()];
        }
        $mappedFg=$mappings->pluck('finished_good_id');
        foreach($goods->whereNotIn('id',$mappedFg) as $fg) $setup[]=$fg->name;
        $plan=null;
        if($request->filled('plan')) {
            $plan=ProductionPlan::where('status','frozen')->findOrFail($request->query('plan'));
            SiteLocation::assertCanAccessSiteRecord($request->user(),(int)$plan->site_id);
        }
        return view('forge.worker.create',['locations'=>$locations,'eligible'=>$eligible,'setup'=>array_unique($setup),
            'canSetup'=>$this->access->can($request->user(),'forge.plan.view'),
            'plan'=>$plan,'remainingQty'=>$plan?max(0,(float)$plan->target_qty-(float)$plan->workOrders()->sum('target_qty')):null,'creationKey'=>old('creation_key',(string)Str::uuid())]);
    }

    public function store(Request $request)
    {
        if(!$request->boolean('worker_create')) return app(WorkOrderController::class)->store($request);
        abort_unless($this->access->can($request->user(),'forge.workorder.create'),403);
        $data=$request->validate(['site_id'=>['required','exists:tan90_locations,id'],'finished_good_id'=>['required','exists:tan90_finished_goods,id'],
            'target_qty'=>['required','numeric','min:0.001'],'due_date'=>['required','date','after_or_equal:today'],
            'batch_number'=>['nullable','string','max:100'],'definition'=>['nullable','string','max:100'],
            'production_plan_id'=>['nullable','integer'],'creation_key'=>['required','uuid']]);
        $wo=$this->direct->create($data,$request->user());
        return redirect()->route('forge.workorders.review',$wo)->with('status','Draft work order created. Review the recipe and release it for material issue.');
    }

    public function show(Request $request,string $step)
    {
        abort_unless(isset(WorkerProduction::STEPS[$step]),404);
        abort_unless($this->access->can($request->user(),'forge.step.'.$step.'.view'),403);
        $history=$request->routeIs('forge.modules.history'); $q=$this->workflow->queue($step,$history);
        $site=SiteLocation::forUser($request->user()); if($site!==null)$q->where('site_id',$site);
        $search=trim((string)$request->query('search','')); $filter=$request->query('filter','all');
        $q->when($search!=='',fn($q)=>$q->where(fn($s)=>$s->where('wo_number','like','%'.$search.'%')->orWhereHas('finishedGood',fn($s)=>$s->where('name','like','%'.$search.'%'))));
        $operations=array_keys(array_filter(WorkerProduction::OPERATIONS,fn($d)=>$d[0]===$step));
        if(!$history && in_array($filter,['pending','in_progress'],true)) {
            $constraint=fn($r)=>$r->whereIn('operation',$operations)->where('status','in_progress')->whereColumn('cycle_no',\Illuminate\Support\Facades\DB::raw('forge_work_orders.rework_generation + 1'));
            $filter==='in_progress'?$q->whereHas('operationRuns',$constraint):$q->whereDoesntHave('operationRuns',$constraint);
        }
        $recentHistory = null;
        if (!$history) {
            $recentHistory = $this->workflow->queue($step, true)
                ->when($site !== null, fn ($query) => $query->where('site_id', $site))
                ->with(['finishedGood', 'site'])->latest('id')->limit(5)->get();
        }
        return view('forge.worker.queue',['orders'=>$q->with(['finishedGood','site','operationRuns'])->latest('id')->paginate(20)->withQueryString(),
            'step'=>$step,'title'=>WorkerProduction::STEPS[$step],'history'=>$history,'filter'=>$filter,
            'recentHistory'=>$recentHistory,
            'canCreate'=>$this->access->can($request->user(),'forge.workorder.create')]);
    }

    public function review(Request $request,WorkOrder $workOrder)
    {
        $this->workflow->authorize($request->user(),$workOrder,'forge.workorder.view');
        return $this->detail($request,$workOrder,'materials',true);
    }

    public function trace(Request $request,WorkOrder $workOrder)
    {
        $this->workflow->authorize($request->user(),$workOrder,'forge.workorder.view');
        // Keep existing work orders on their established trace screen. New
        // worker-ready orders use the six dedicated pages from workflow v3.
        if ((int) $workOrder->workflow_version < 3) {
            return app(WorkOrderController::class)->show($request, $workOrder);
        }
        $this->workflow->load($workOrder);
        return view('forge.worker.trace',['wo'=>$workOrder->load(['recipe','bom','rmWarehouse','fgWarehouse','creator']),
            'steps'=>collect(WorkerProduction::STEPS)->filter(fn($label,$key)=>$this->access->can($request->user(),'forge.step.'.$key.'.view')),
            'canCorrect'=>$this->access->can($request->user(),'forge.operation.correct')]);
    }

    public function workOrder(Request $request,string $step,WorkOrder $workOrder,?string $operation=null)
    {
        abort_unless(isset(WorkerProduction::STEPS[$step]),404);
        $this->workflow->authorize($request->user(),$workOrder,'forge.step.'.$step.'.view');
        return $this->detail($request,$workOrder,$step,false,$operation);
    }

    private function detail(Request $request,WorkOrder $wo,string $step,bool $review=false,?string $requestedOperation=null)
    {
        $this->workflow->load($wo); $wo->load(['recipe','bom','rmWarehouse','fgWarehouse','inventoryFgItem','reusablePcmItem']);
        $operation=$requestedOperation??$this->workflow->operation($wo,$step);
        abort_unless(isset(WorkerProduction::OPERATIONS[$operation]) && WorkerProduction::OPERATIONS[$operation][0]===$step,404);
        $cycle=(int)$wo->rework_generation+1;
        $run=$wo->operationRuns->first(fn($r)=>$r->operation===$operation && (int)$r->cycle_no===$cycle && (int)$r->context_id===0);
        $bomItems=$wo->inventoryFgItem?->bomLines()->with('component')->get()??collect();
        $stock=$step==='materials'&&$wo->rm_warehouse_id?app(InventoryService::class)->atWarehouse((int)$wo->rm_warehouse_id)->balances($bomItems->pluck('component')->filter()):[];
        $qc=$wo->qcResults->sortByDesc('id')->first();
        $recovered=max(0,(float)$wo->qcResults->whereNotNull('released_at')->sum('recovered_pcm_qty')+(float)$wo->processRecords->sum('recovered_pcm_qty')-(float)$wo->processRecords->sum('recovered_pcm_used_qty'));
        $readonly=$request->boolean('history')||in_array($wo->status,['closed','rejected'],true);
        $blockedHold=$wo->hasOpenHold();
        return view('forge.worker.work',['wo'=>$wo,'step'=>$step,'title'=>WorkerProduction::STEPS[$step],'operation'=>$operation,'review'=>$review,
            'previousStep'=>collect(['mixing'=>'materials','filling-sealing'=>'mixing','quality'=>'filling-sealing','consumption-fg'=>'quality','finished-goods'=>'consumption-fg'])->get($step),
            'visibleSteps'=>collect(WorkerProduction::STEPS)->filter(fn($label,$key)=>$this->access->can($request->user(),'forge.step.'.$key.'.view')),
            'canSetupBins'=>$this->access->can($request->user(),'grn.bins.view'),
            'run'=>$run,'cycle'=>$cycle,'qc'=>$qc,'recovered'=>$recovered,'readonly'=>$readonly,'workflow'=>$this->workflow,
            'bundleDefaults'=>$step==='consumption-fg'?app(\App\Services\Forge\BundleService::class)->materialDefaults($wo):collect(),
            'bomItems'=>$bomItems,'materialStock'=>$stock,'machines'=>\App\Models\Forge\Machine::where('site_id',$wo->site_id)->where('status','active')->orderBy('name')->get(),
            'bins'=>$step==='finished-goods'?Bin::with('shelf.rack.zone')->where('status','active')->where('approval_status','approved')->get()->filter(fn($b)=>(int)$b->shelf?->rack?->zone?->tan90_warehouse_id===(int)$wo->fg_warehouse_id):collect(),
            'requestLines'=>\Illuminate\Support\Facades\DB::table('forge_material_request_lines as l')->join('forge_material_requests as r','r.id','=','l.material_request_id')->join('inventory_items as i','i.id','=','l.inventory_item_id')->where('r.work_order_id',$wo->id)->get(['l.*','i.code','i.name','i.unit']),
            'blockedHold'=>$blockedHold,'canReviewHold'=>$this->access->can($request->user(),'forge.ipqc.record'),
            'canOperate'=>!$blockedHold && $this->access->can($request->user(),WorkerProduction::OPERATIONS[$operation][1]),
            'canRelease'=>$this->access->can($request->user(),'forge.workorder.release'),
            'canApprove'=>$this->access->can($request->user(),'forge.production.approve'),
            'canQcApprove'=>$this->access->can($request->user(),'forge.finalqc.release'),
            'canReceive'=>$this->access->can($request->user(),'flow.inventory.receive'),
            'canTrace'=>$this->access->can($request->user(),'forge.workorder.view')]);
    }
}
