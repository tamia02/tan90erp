<x-app-layout>
@include('forge.worker._style')
<div class="pw-shell">
<header class="pw-head pw-row"><div><div class="pw-kicker">Production{{ $review?' · Work Order Review':' · Step '.(array_search($step,array_keys(\App\Services\Forge\WorkerProduction::STEPS),true)+1) }}</div><h1>{{ $review?'Review Work Order':$title }}</h1></div><div class="pw-actions"><a class="pw-btn" href="{{ route('forge.modules.show',['step'=>$step]) }}">Work Queue</a><a class="pw-btn" href="{{ route('forge.modules.history',['step'=>$step]) }}">History</a>@if($canTrace)<a class="pw-btn" href="{{ route('forge.workorders.show',$wo) }}">View Order Trace</a>@endif</div></header>
@include('forge.worker._notices')
<div class="pw-order pw-row"><div><strong>{{ $wo->finishedGood?->name }}</strong><p class="pw-muted">{{ $wo->wo_number }} · {{ $wo->target_qty }} {{ $wo->uom }} · {{ $wo->site?->name ?? 'Site not set' }}@if($cycle>1) · Rework round {{ $cycle-1 }}@endif</p></div><span class="pw-badge">{{ str($wo->status)->replace('_',' ')->headline() }}</span></div>
@php
    $flowCue = match ($step) {
        'materials' => [
            'next' => $wo->status === 'draft' ? 'Manager: review and release this work order.' : ($wo->materialRequests->contains('status', 'requested') ? 'Record the handover and issue the requested materials from this warehouse.' : ($wo->status === 'material_issued' ? 'Open PCM Making to start the mix.' : 'Request the approved recipe quantities for this work order.')),
            'after' => 'Issued materials move to PCM Making. Stock shortages must be replenished through an approved stock receipt or adjustment.',
        ],
        'mixing' => [
            'next' => $wo->status === 'released' ? 'Complete material issue first.' : ($wo->status === 'rework' ? 'Resume this work order to prepare recovered PCM.' : ($workflow->hasRecord($wo, 'mixing') ? 'PCM mix recorded; continue to Filling & Sealing.' : ($run ? 'Complete the one continuous PCM mix.' : 'Start and record one continuous PCM mix.'))),
            'after' => 'After material issue, prepare one mix; the solution then moves to Filling & Sealing.',
        ],
        'filling-sealing' => [
            'next' => ! in_array($wo->status, ['in_progress', 'reconciliation'], true) ? 'Complete PCM Making first.' : ($workflow->hasRecord($wo, $operation) ? 'Production operation recorded; submit output for approval.' : ($run ? 'Complete '.\App\Services\Forge\WorkerProduction::OPERATIONS[$operation][2].'.' : 'Record '.$operation.' on the production machine.')),
            'after' => 'After sealing, a manager approves output and QC inspects the finished units.',
        ],
        'quality' => [
            'next' => $wo->status !== 'final_qc_pending' ? 'Wait for sealing and production approval.' : ($qc && ! $qc->released_at ? ($canQcApprove ? 'Approve the saved QC decision.' : 'Wait for the authorized QC approver.') : ($workflow->hasRecord($wo, 'quality') ? 'QC is recorded; continue when the decision is released.' : 'Record pressure, leak and physical inspection results.')),
            'after' => 'Accepted units move to Bundle; rejected units return as recovered PCM on this work order.',
        ],
        'consumption-fg' => [
            'next' => $wo->status !== 'bundle_pending' ? 'Wait for QC release.' : ($workflow->hasRecord($wo, 'bundling') ? 'Bundle confirmation is complete; open FG Store.' : 'Confirm the physical good and defective bundle quantities.'),
            'after' => 'Confirmed bundles move to FG Store for receipt and bin putaway.',
        ],
        'finished-goods' => [
            'next' => $wo->status !== 'released_to_fg' ? 'Wait for bundle confirmation and FG release.' : (! $wo->batch?->inventoryLot ? 'Receive the approved finished batch into the FG warehouse.' : ($run ? 'Complete putaway into the selected approved bin.' : 'Start putaway and select an approved FG bin.')),
            'after' => 'After putaway, finished goods are stored and available for dispatch handoff.',
        ],
        default => ['next' => 'Follow the action shown below.', 'after' => 'The next owner will see the completed order in their queue.'],
    };
    if (in_array($wo->status, ['closed', 'rejected'], true)) {
        $flowCue = [
            'next' => $wo->status === 'closed' ? 'This production order is complete.' : 'This order was rejected; review its trace before any further action.',
            'after' => 'Open View Order Trace or History to review the saved production and inventory records.',
        ];
    }
@endphp
<section class="pw-flow-cue" role="status"><strong>Next action</strong><span>{{ $flowCue['next'] }}</span><span class="pw-muted">After this: {{ $flowCue['after'] }}</span></section>
@if($blockedHold)<div class="pw-error">This order has an open quality hold. Resolve the hold before continuing.@if($canReviewHold)<a class="underline" href="{{ route('forge.quality-holds.index',['work_order'=>$wo->id]) }}">Review Quality Hold</a>@endif</div>@endif
@if($review)
    <section class="pw-card pw-stack"><div class="pw-row"><h2>Recipe & Warehouses</h2><span class="pw-badge">{{ $wo->recipe?->code??$wo->bom?->code }}</span></div><p class="pw-muted">RM/PM: {{ $wo->rmWarehouse?->name }} · FG: {{ $wo->fgWarehouse?->name }}</p>@include('forge.worker._recipe')
    @if($wo->status==='draft' && $canRelease)<form method="post" action="{{ route('forge.workorders.release',$wo) }}">@csrf<button class="pw-btn pw-primary">Release for Material Issue</button></form>@elseif($wo->status==='draft')<p class="pw-muted">Waiting for a manager to release this order.</p>@else<p class="pw-notice">Work order released. Material handover is handled in the RM & PM Issue queue.</p>@endif</section>
@elseif($readonly)
    @include('forge.worker._records')
@elseif($step==='materials')
    @include('forge.worker._materials')
@else
    @if($step==='filling-sealing')<nav class="pw-actions" aria-label="Production operations"><a class="pw-btn {{ $operation==='filling'?'pw-primary':'' }}" href="{{ route('forge.modules.operation',['step'=>$step,'workOrder'=>$wo,'operation'=>'filling']) }}">Filling</a><a class="pw-btn {{ $operation==='sealing'?'pw-primary':'' }}" href="{{ route('forge.modules.operation',['step'=>$step,'workOrder'=>$wo,'operation'=>'sealing']) }}">Sealing</a></nav>@endif
    @if($step==='finished-goods' && $wo->batch && !$wo->batch->inventoryLot)
        <section class="pw-card pw-stack"><h2>Receive FG Batch</h2><p>{{ $wo->batch->batch_number }} · {{ $wo->batch->qty }} {{ $wo->uom }} · {{ $wo->fgWarehouse?->name }}</p>@if($canReceive)<form method="post" action="{{ route('flow.inventory.receive',$wo->batch) }}">@csrf<button class="pw-btn pw-primary">Receive Batch</button></form>@else<p class="pw-muted">Waiting for the FG receiver.</p>@endif</section>
    @elseif($step==='filling-sealing' && $workflow->hasRecord($wo,'sealing'))
        <section class="pw-card pw-stack"><h2>Production Approval</h2>@foreach($wo->productionEntries->where('cycle_no',$cycle) as $entry)<p>{{ $entry->good_qty }} good · {{ $entry->rejected_qty }} rejected {{ $wo->uom }} · {{ str($entry->status)->headline() }}</p>@endforeach
        @if($wo->productionEntries->where('cycle_no',$cycle)->contains('status','draft') && $canApprove)<form method="post" action="{{ route('forge.workorders.approve-output',$wo) }}">@csrf<button class="pw-btn pw-primary">Approve & Send to QC</button></form>@else<p class="pw-muted">Waiting for production approval or the next production owner.</p>@endif</section>
    @elseif($step==='quality' && $qc && (int)$qc->cycle_no===$cycle)
        <section class="pw-card pw-stack"><h2>QC Decision</h2><p>{{ $qc->accepted_qty }} accepted · {{ $qc->rejected_qty }} rejected · {{ $qc->rework_qty }} rework {{ $wo->uom }}</p><p class="pw-muted">Leak: {{ $qc->leak_test }} · Pressure: {{ $qc->pressure_test }} · Physical: {{ $qc->physical_check }}</p>
        @if(!$qc->released_at && $canQcApprove)<form method="post" action="{{ route('forge.final-qc.release',$qc) }}">@csrf<button class="pw-btn pw-primary">Approve QC Decision</button></form>@else<p class="pw-muted">{{ $qc->released_at?'QC decision approved. The next production owner can continue.':'Waiting for QC approval.' }}</p>@endif</section>
    @elseif($workflow->pending($wo,$step) && !$workflow->hasRecord($wo,$operation))
        <section class="pw-card pw-stack"><div class="pw-row"><h2>{{ $cycle>1&&$operation==='mixing'?'Prepare Recovered PCM':\App\Services\Forge\WorkerProduction::OPERATIONS[$operation][2] }}</h2>@if($run)<span class="pw-badge">Started {{ $run->started_at->format('d M, H:i') }} · {{ $run->payload['manpower'] }} people</span>@endif</div>
        @if(!$canOperate)<p class="pw-muted">This action belongs to the assigned production worker.</p>
        @elseif(!$run)
            @if($operation==='putaway' && $bins->isEmpty())
                <div class="pw-error">Setup required: no approved active bin in {{ $wo->fgWarehouse?->name }}.</div>
                @if($canSetupBins)<a class="pw-btn" href="{{ route('grn.bins') }}">Shelf & Bin Setup</a>@endif
            @else
                @include('forge.worker._start')
            @endif
        @else
            <form method="post" action="{{ route('forge.workorders.operations.complete',[$wo,$run]) }}" class="pw-stack">@csrf
                @if($operation==='bundling' && data_get($run->payload,'draft_saved_at'))<p class="pw-notice">Saved progress from {{ \Illuminate\Support\Carbon::parse(data_get($run->payload,'draft_saved_at'))->format('d M Y, H:i') }}. You can continue editing below.</p>@endif
                @include('forge.worker._operation-fields')
                <div class="pw-actions">
                    @if($operation==='bundling')<button class="pw-btn" type="submit" formaction="{{ route('forge.workorders.operations.draft',[$wo,$run]) }}" formmethod="post" formnovalidate>Save Progress</button>@endif
                    <button class="pw-btn pw-primary">Complete {{ \App\Services\Forge\WorkerProduction::OPERATIONS[$operation][2] }}</button>
                </div>
            </form>
        @endif
        </section>
    @else<section class="pw-card pw-stack"><p class="pw-muted">{{ $workflow->hasRecord($wo,$operation)?'This operation is complete. The next production owner can continue from their queue.':'This job is not ready for this operation. Review its current status or return to the work queue.' }}</p>@if(!$workflow->hasRecord($wo,$operation) && $previousStep && $visibleSteps->has($previousStep))<div><a class="pw-btn" href="{{ route('forge.modules.workorder',['step'=>$previousStep,'workOrder'=>$wo]) }}">Open {{ $visibleSteps[$previousStep] }}</a></div>@endif</section>@endif
    @include('forge.worker._records')
@endif
</div>
</x-app-layout>
