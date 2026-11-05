<x-app-layout>
    @include('access-control._style')
    <style>
        #forge-work-order .wo-heading{min-width:0;overflow-wrap:anywhere}
        #forge-work-order .wo-reference{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.78rem;letter-spacing:.01em;color:#53635b;overflow-wrap:anywhere}
        #forge-work-order .wo-summary{grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr))}
        #forge-work-order .wo-field{display:grid;gap:.35rem;align-content:start;color:#53635b;font-size:.78rem;font-weight:700}
        #forge-work-order .wo-field .access-input{color:#17211b;font-weight:400}
        #forge-work-order .wo-step-card{border-left:4px solid #147d4f}
        #forge-work-order .wo-action-area{scroll-margin-top:1rem}
        #forge-work-order .access-btn{min-height:2.75rem}
        @if($moduleStep)
            #forge-work-order .wo-module-section:not(.wo-module-{{ $moduleStep }}){display:none}
        @endif
        #forge-work-order :is(input,select,textarea,button,a):focus-visible{outline:3px solid #65a98a;outline-offset:2px}
        @media(max-width:640px){#forge-work-order .wo-summary{grid-template-columns:repeat(2,minmax(0,1fr));gap:.65rem}#forge-work-order .wo-summary>.access-card{padding:.8rem!important;min-width:0}#forge-work-order .wo-summary .font-bold{font-size:.9rem;overflow-wrap:anywhere}#forge-work-order .wo-top{padding:1rem!important}}
    </style>
    <div id="forge-work-order" class="access-shell max-w-7xl mx-auto space-y-5">
        <div class="access-top p-5 flex items-center justify-between flex-wrap gap-3">
            <div class="wo-top min-w-0">
                <div class="text-sm access-muted">Production / {{ $moduleStep ? 'Step '.(['materials' => '01', 'mixing' => '02', 'filling-sealing' => '03', 'consumption-fg' => '05'][$moduleStep] ?? '') : 'Work Orders' }}</div>
                <h1 class="wo-heading text-2xl font-bold">{{ $wo->finishedGood?->name ?? 'Work order' }}</h1>
                <div class="wo-reference" title="{{ $wo->wo_number }}">{{ $wo->wo_number }}</div>
            </div>
            <span class="access-chip">{{ str($wo->status)->replace('_', ' ')->headline() }}</span>
        </div>

        @if(session('status'))<div class="access-card p-3 text-sm">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="access-card p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

        @if($moduleStep)
            <nav class="access-card p-4 flex flex-wrap items-center gap-2" aria-label="Production steps">
                @foreach(['materials' => 'RM & PM Issue', 'mixing' => 'PCM Making', 'filling-sealing' => 'Filling & Sealing', 'quality' => 'Pressure & Leak Test', 'consumption-fg' => 'Consumption & FG', 'finished-goods' => 'FG Store'] as $key => $label)
                    <a class="access-btn {{ $moduleStep === $key ? 'access-btn-primary' : '' }}" href="{{ in_array($key, ['quality', 'finished-goods']) ? route($key === 'quality' ? 'forge.final-qc.index' : 'flow.inventory.index', ['work_order' => $wo->id]) : route('forge.modules.workorder', ['step' => $key, 'workOrder' => $wo]) }}" @if($moduleStep === $key) aria-current="step" @endif>{{ $loop->iteration }}. {{ $label }}</a>
                @endforeach
                <a class="access-btn" href="{{ route('forge.workorders.show', $wo) }}">Complete work-order record</a>
            </nav>
            <div class="access-card p-4 border-l-4 border-green-600">
                <strong>{{ ['materials' => 'Issue the approved recipe materials using FIFO lots.', 'mixing' => 'Prepare one PCM solution batch for this work order.', 'filling-sealing' => 'Record the single continuous feed, filling and sealing output.', 'consumption-fg' => 'Review QC-approved quantities and automatic material reconciliation.'][$moduleStep] ?? '' }}</strong>
                <p class="access-muted text-sm mt-1">{{ $moduleStep === 'consumption-fg' ? 'After Quality releases the result, count physical bundles here before the FG batch posts. Then receive and put away the batch in FG Store.' : 'Complete the action below; the saved result stays on this page and in the order trace.' }}</p>
            </div>
        @endif

        <div class="access-grid wo-summary">
            <div class="access-card p-4">
                <div class="access-muted text-sm">Target / Good / Rework / Rejected</div>
                <div class="font-bold">{{ $wo->target_qty }} / {{ $wo->good_qty }} / {{ $wo->rework_qty }} / {{ $wo->rejected_qty }} {{ $wo->uom }}</div>
            </div>
            <div class="access-card p-4">
                <div class="access-muted text-sm">BOM / Recipe / Routing</div>
                <div class="font-bold">{{ $wo->bom?->code ?? '—' }} / {{ $wo->recipe?->code ?? '—' }} / {{ $wo->routing?->code ?? '—' }}</div>
            </div>
            <div class="access-card p-4">
                <div class="access-muted text-sm">Batch Number</div>
                <div class="font-bold">{{ $wo->batch_number ?? $wo->batch?->batch_number ?? '—' }}</div>
            </div>
            @if ($wo->inventory_fg_item_id)
                <div class="access-card p-4">
                    <div class="access-muted text-sm">Stock mapping</div>
                    <div class="font-bold">FG SKU #{{ $wo->inventory_fg_item_id }} · Site #{{ $wo->site_id }} · RM WH #{{ $wo->rm_warehouse_id }} · FG WH #{{ $wo->fg_warehouse_id }}</div>
                </div>
            @endif
            @if ($wo->material_handover_started_at)
                <div class="access-card p-4">
                    <div class="access-muted text-sm">RM/PM handover · people</div>
                    <div class="font-bold">{{ $wo->material_handover_started_at->format('d M, H:i') }} → {{ $wo->material_handover_ended_at?->format('d M, H:i') }} · {{ $wo->material_handover_manpower }}</div>
                </div>
            @endif
        </div>

        @php($sourceOrderLine = $wo->productionPlan?->sourceOrderLine)
        @php($sourceOrder = $sourceOrderLine?->order)
        @php($materialIssues = $wo->materialIssues->where('movement_type', 'issue'))
        @php($approvedEntries = $wo->productionEntries->where('status', 'approved'))
        <section class="access-card p-5" aria-labelledby="wo-order-trace">
            <h2 id="wo-order-trace" class="font-bold text-lg">Order trace</h2>
            <p class="access-muted text-sm mt-1">Follow this job from its source demand through approved production, quality release and finished-goods receipt. Each reference links the quantity and site to the next control point.</p>
            <ol class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <li class="rounded-lg border p-3" style="border-color:#dfe7e2">
                    <strong class="block text-sm">{{ $sourceOrder ? 'Sales demand' : 'Manual production demand' }}</strong>
                    @if($sourceOrder)
                        <span class="text-sm">{{ $sourceOrder->order_number }} · line {{ $sourceOrderLine->id }}</span>
                        <span class="access-muted block text-xs mt-1">{{ $sourceOrderLine->qty_ordered }} {{ $sourceOrderLine->uom }} ordered · {{ str($sourceOrder->status)->headline() }}</span>
                        @if($canViewSalesOrder)<a class="underline text-xs" href="{{ route('flow.orders.show', $sourceOrder) }}">Open sales order</a>@endif
                    @else
                        <span class="access-muted block text-sm">{{ $wo->productionPlan?->plan_number ?? 'No linked sales line' }}</span>
                        <span class="access-muted block text-xs mt-1">{{ $wo->productionPlan?->target_qty ?? $wo->target_qty }} {{ $wo->uom }} planned</span>
                    @endif
                </li>
                <li class="rounded-lg border p-3" style="border-color:#dfe7e2">
                    <strong class="block text-sm">Production plan</strong>
                    @if($wo->productionPlan)
                        <a class="text-sm underline" href="{{ route('forge.plans.show', $wo->productionPlan) }}">{{ $wo->productionPlan->plan_number }}</a>
                    @else
                        <span class="text-sm">Standalone work order</span>
                    @endif
                    <span class="access-muted block text-xs mt-1">{{ str($wo->productionPlan?->status ?? 'not linked')->headline() }} · target {{ $wo->productionPlan?->target_qty ?? $wo->target_qty }} {{ $wo->uom }}</span>
                </li>
                <li class="rounded-lg border p-3" style="border-color:#dfe7e2">
                    <strong class="block text-sm">Work order</strong>
                    <span class="text-sm">{{ $wo->wo_number }} · {{ str($wo->status)->headline() }}</span>
                    <span class="access-muted block text-xs mt-1">Created {{ $wo->created_at?->format('d M Y, H:i') ?? 'time not recorded' }}@if($wo->released_at) · Released {{ $wo->released_at->format('d M Y, H:i') }}@endif</span>
                </li>
                <li class="rounded-lg border p-3" style="border-color:#dfe7e2">
                    <strong class="block text-sm">Material issue</strong>
                    <span class="text-sm">{{ $materialIssues->count() }} issue record(s)</span>
                    <span class="access-muted block text-xs mt-1">{{ $materialIssues->groupBy('uom')->map(fn ($issues, $uom) => number_format((float) $issues->sum('qty'), 3).' '.$uom)->implode(' · ') }} issued · stock reduces at issue</span>
                </li>
                <li class="rounded-lg border p-3" style="border-color:#dfe7e2">
                    <strong class="block text-sm">Shop-floor operations</strong>
                    <span class="text-sm">{{ $wo->jobCards->where('status', 'completed')->count() }} / {{ $wo->jobCards->count() }} job cards complete</span>
                    <span class="access-muted block text-xs mt-1">{{ $processRecords->count() }} filling/sealing record(s) · {{ $mixingRecords->count() }} mixing record(s)</span>
                </li>
                <li class="rounded-lg border p-3" style="border-color:#dfe7e2">
                    <strong class="block text-sm">Approved production</strong>
                    <span class="text-sm">{{ $approvedEntries->count() }} approved entry(s)</span>
                    <span class="access-muted block text-xs mt-1">{{ $approvedEntries->sum('good_qty') }} good · {{ $approvedEntries->sum('rework_qty') }} rework · {{ $approvedEntries->sum('rejected_qty') }} rejected {{ $wo->uom }}</span>
                </li>
                <li class="rounded-lg border p-3" style="border-color:#dfe7e2">
                    <strong class="block text-sm">Final quality</strong>
                    @if($wo->finalQcResult)
                        <span class="text-sm">{{ str($wo->finalQcResult->result)->headline() }} · {{ $wo->finalQcResult->accepted_qty }} {{ $wo->uom }} accepted</span>
                        <span class="access-muted block text-xs mt-1">{{ $wo->finalQcResult->released_at ? 'Released '.$wo->finalQcResult->released_at->format('d M Y, H:i') : 'Awaiting quality manager release' }}</span>
                    @else
                        <span class="access-muted block text-sm">Awaiting final inspection</span>
                    @endif
                </li>
                <li class="rounded-lg border p-3" style="border-color:#dfe7e2">
                    <strong class="block text-sm">FG receipt and putaway</strong>
                    @if($wo->batch)
                        <span class="text-sm">{{ $wo->batch->batch_number }} · {{ $wo->batch->qty }} {{ $wo->batch->uom }} · {{ str($wo->batch->status)->headline() }}</span>
                        <span class="access-muted block text-xs mt-1">@if($wo->batch->inventoryLot)Received {{ $wo->batch->inventoryLot->received_at?->format('d M Y, H:i') ?? 'in ledger' }} · {{ $wo->batch->inventoryLot->bin ? 'put away to '.$wo->batch->inventoryLot->bin : 'putaway pending' }}@else Awaiting finished-goods receipt into the linked warehouse @endif</span>
                    @else
                        <span class="access-muted block text-sm">No released batch yet</span>
                    @endif
                </li>
            </ol>
        </section>

        @php($showStepActions = ! $moduleStep
            || ($moduleStep === 'materials' && in_array($wo->status, ['draft', 'released']))
            || ($moduleStep === 'mixing' && in_array($wo->status, ['material_issued', 'rework']))
            || ($moduleStep === 'filling-sealing' && in_array($wo->status, ['rework', 'reconciliation']))
            || ($moduleStep === 'consumption-fg' && in_array($wo->status, ['released_to_fg', 'rejected'])))
        @if($showStepActions)
        <div class="access-card p-5 flex flex-wrap gap-2 wo-module-section wo-module-materials wo-module-mixing wo-module-filling-sealing wo-module-consumption-fg">
            <h2 class="font-bold w-full mb-1">Actions</h2>
            @if ($wo->status === 'draft')
                <form class="wo-module-section wo-module-materials" method="post" action="{{ route('forge.workorders.release', $wo) }}">{{ csrf_field() }}<button class="access-btn access-btn-primary">Release</button></form>
            @endif
            @if ($wo->status === 'released' && (int) $wo->workflow_version < 2)
                <form class="wo-module-section wo-module-materials" method="post" action="{{ route('forge.workorders.reserve-material', $wo) }}">{{ csrf_field() }}<button class="access-btn">Reserve Material</button></form>
            @endif
            @if ($wo->status === 'material_issued')
                <form class="wo-module-section wo-module-mixing" method="post" action="{{ route('forge.workorders.start', $wo) }}">{{ csrf_field() }}<button class="access-btn access-btn-primary">Start Production</button></form>
            @endif
            @if ($wo->status === 'rework')
                <form class="wo-module-section wo-module-mixing wo-module-filling-sealing" method="post" action="{{ route('forge.workorders.resume-rework', $wo) }}">{{ csrf_field() }}<button class="access-btn access-btn-primary">Resume this work order for rework</button></form>
            @endif
            @if ($wo->status === 'reconciliation')
                <form class="wo-module-section wo-module-filling-sealing" method="post" action="{{ route('forge.workorders.send-to-final-qc', $wo) }}">{{ csrf_field() }}<button class="access-btn access-btn-primary">Send to Final QC</button></form>
            @endif
            @if (in_array($wo->status, ['released_to_fg', 'rejected']))
                <form class="wo-module-section wo-module-consumption-fg" method="post" action="{{ route('forge.workorders.close', $wo) }}">{{ csrf_field() }}<button class="access-btn">Close Work Order</button></form>
            @endif
        </div>
        @endif

        @if ((int) $wo->workflow_version >= 2)
            @if ($wo->status === 'rework')
                <section class="access-card p-4 border-l-4 border-amber-500" role="status">
                    <h2 class="font-bold">Rework round {{ $wo->rework_generation }} · same order</h2>
                    <p class="access-muted text-sm mt-1">Recovered PCM stays with this work order. It is not returned to warehouse stock. Resume this order, record the correction, then send it through leak and pressure QC again.</p>
                    <p class="text-sm mt-2">Already accepted and held quantities remain in the order trace: {{ $wo->qc_accepted_carry_qty }} accepted, {{ $wo->qc_rejected_carry_qty }} rejected {{ $wo->uom }}.</p>
                </section>
            @endif
            <section id="material-request" class="access-card wo-step-card p-5 space-y-4 wo-action-area wo-module-section wo-module-materials">
                <h2 class="font-bold">Step 1 · Request Items and FIFO handover</h2>
                <p class="access-muted text-sm">Operator requests full, partial or top-up BOM quantities. Production Manager / Shift Supervisor checks site stock and issues FIFO lots. Extra BOM quantity needs a reason.</p>
                @if(in_array($wo->status, ['released','material_issued','in_progress','reconciliation']))
                <form method="post" action="{{ route('forge.workorders.material-requests.store', $wo) }}" class="space-y-3">@csrf
                    @foreach($bomItems as $index => $line)
                        <div class="access-grid border-b pb-2">
                            <div class="text-sm"><strong>{{ $line->component?->code ?: $line->component_item_id }}</strong> · {{ $line->component?->safeDisplayName() }}<br><span class="access-muted">BOM {{ $line->qty_per_unit }} {{ $line->component?->unit }}/FG · total {{ (float)$line->qty_per_unit * (float)$wo->target_qty }}</span></div>
                            <input type="hidden" name="lines[{{ $index }}][inventory_item_id]" value="{{ $line->component_item_id }}">
                            <label class="wo-field">Quantity to request
                                <input class="access-input" type="number" step="0.001" min="0.001" name="lines[{{ $index }}][qty]" placeholder="Enter quantity" aria-label="Quantity to request for {{ $line->component?->code ?: $line->component_item_id }}">
                            </label>
                            <label class="wo-field">Reason for quantity above BOM
                                <input class="access-input" name="lines[{{ $index }}][extra_reason]" placeholder="Required only for extra quantity" aria-label="Extra quantity reason for {{ $line->component?->code ?: $line->component_item_id }}">
                            </label>
                        </div>
                    @endforeach
                    <button class="access-btn access-btn-primary">Send Request Items</button>
                </form>
                @endif
                @foreach($materialRequests as $materialRequest)
                    <div class="border rounded p-3 space-y-2 text-sm">
                        <strong>Request #{{ $materialRequest->id }}</strong> · {{ $materialRequest->status }}
                        @foreach($requestLines->where('material_request_id', $materialRequest->id) as $line)<p>{{ $line->code ?: $line->name }} · {{ $line->qty }} {{ $line->unit }} @if($line->extra_reason) · {{ $line->extra_reason }} @endif</p>@endforeach
                        @if($materialRequest->status === 'requested')
                        <form method="post" action="{{ route('forge.workorders.material-requests.issue', [$wo, $materialRequest->id]) }}" class="access-grid">@csrf
                            <label class="wo-field">Handover start<input class="access-input" type="datetime-local" name="handover_started_at" required></label>
                            <label class="wo-field">Handover end<input class="access-input" type="datetime-local" name="handover_ended_at" required></label>
                            <label class="wo-field">People involved<input class="access-input" type="number" min="1" name="manpower" placeholder="Number of people" required></label>
                            <button class="access-btn">Approve and issue FIFO</button>
                        </form>
                        @else
                            <p class="access-muted">Handover {{ $materialRequest->handover_started_at }} → {{ $materialRequest->handover_ended_at }} · {{ $materialRequest->manpower }} people</p>
                        @endif
                    </div>
                @endforeach
            </section>
        @endif

        @if((int) $wo->workflow_version >= 2 && $consumptionRecord)
            @php($automaticConsumption = (bool) $consumptionRecord->auto_reconciled)
            <section id="automatic-fg-record" class="access-card wo-step-card p-5 space-y-3 wo-module-section wo-module-consumption-fg" aria-labelledby="automatic-fg-title">
                <h2 id="automatic-fg-title" class="font-bold">Step 5 · Consumption & FG records · {{ $automaticConsumption ? 'BOM reconciled' : 'historical manual record' }}</h2>
                <p class="access-muted text-sm">Final QC released {{ $wo->finalQcResult?->accepted_qty }} {{ $wo->uom }} for sale. FG batch quantity is {{ $wo->batch?->qty }} {{ $wo->batch?->uom }} and the consumption quantities are shown below.</p>
                @if($automaticConsumption)
                    <p class="access-muted text-sm">The system reconciled material use from the approved BOM against all approved production output across work-order rounds.</p>
                @else
                    <p class="access-muted text-sm">This work order was reconciled manually before automatic consumption posting was enabled on Stage. New final QC releases use the BOM-based automatic step.</p>
                @endif
                <div class="grid sm:grid-cols-2 gap-2 text-sm">
                    <p><strong>FG batch:</strong> {{ $wo->batch?->batch_number }} · {{ $wo->batch?->qty }} {{ $wo->batch?->uom }}</p>
                    <p><strong>QC not accepted:</strong> {{ (float)($wo->finalQcResult?->rejected_qty ?? 0) + (float)($wo->finalQcResult?->rework_qty ?? 0) }} {{ $wo->uom }} · held from FG stock</p>
                </div>
                <div class="space-y-1 text-sm">
                    <strong>{{ $automaticConsumption ? 'Automatic' : 'Manual historical' }} RM/PM reconciliation · issued = used + scrap + returned</strong>
                    @foreach($consumptionLines as $line)
                        <p>{{ $line->code ?: $line->name }} · used {{ $line->used_qty }} {{ $line->unit }} · scrap {{ $line->scrap_qty }} · returned {{ $line->return_qty }}</p>
                    @endforeach
                </div>
                @if($wo->batch?->inventoryLot)
                    <p class="access-muted text-sm">FG store: {{ str($wo->batch->inventoryLot->status)->headline() }} · {{ $wo->batch->inventoryLot->zone ?? 'bin pending' }} / {{ $wo->batch->inventoryLot->bin ?? 'choose a master bin' }}</p>
                @else
                    <p class="access-muted text-sm">Next: FG Store receives the batch, then selects the recommended active bin and records putaway time and manpower.</p>
                @endif
            </section>
        @endif

        @if($wo->status === 'bundle_pending' && (int) $wo->workflow_version >= 2)
            <section class="access-card p-5 space-y-3 wo-module-section wo-module-consumption-fg">
                <h2 class="font-bold">Step 5 · Physical bundle count required</h2>
                <p class="access-muted text-sm">QC accepted {{ $wo->finalQcResult?->accepted_qty }} {{ $wo->uom }}. Count actual bundles and loose units before the approved Inventory BOM can reconcile materials and post one FG batch.</p>
                <a class="access-btn access-btn-primary" href="{{ route('forge.modules.workorder', ['step' => 'consumption-fg', 'workOrder' => $wo]) }}">Open bundle count page</a>
            </section>
        @endif

        @if ($wo->status === 'material_reserved')
            @if ($wo->inventory_fg_item_id)
                <div class="access-card p-4 space-y-2 wo-module-section wo-module-materials">
                    <h2 class="font-bold">Reserved RM/PM</h2>
                    @foreach ($reservations as $reservation)
                        <p class="text-sm">{{ $reservation->code ?: $reservation->name }} — {{ $reservation->qty }} {{ $reservation->unit }}</p>
                    @endforeach
                    <form method="post" action="{{ route('forge.workorders.issue-material', $wo) }}" class="space-y-3">
                        @csrf
                        <div class="access-grid">
                            <label class="text-sm">Handover start <input class="access-input" name="handover_started_at" type="datetime-local" required></label>
                            <label class="text-sm">Handover end <input class="access-input" name="handover_ended_at" type="datetime-local" required></label>
                            <input class="access-input" name="manpower" type="number" min="1" step="1" placeholder="People handing over material" required>
                        </div>
                        <button class="access-btn access-btn-primary">Issue reserved materials and post stock</button>
                    </form>
                </div>
            @else
            <form class="access-card p-4 space-y-3 wo-module-section wo-module-materials" method="post" action="{{ route('forge.workorders.issue-material', $wo) }}">
                @csrf
                <h2 class="font-bold">Issue Material</h2>
                <div class="access-grid">
                    <input class="access-input" name="lines[0][item_code]" placeholder="Item code" required>
                    <input class="access-input" name="lines[0][item_name]" placeholder="Item name" required>
                    <input class="access-input" name="lines[0][lot_number]" placeholder="Lot number">
                    <input class="access-input" name="lines[0][qty]" type="number" step="0.001" min="0.001" placeholder="Quantity" required>
                    <input class="access-input" name="lines[0][uom]" placeholder="UOM" required>
                </div>
                <button class="access-btn access-btn-primary">Issue</button>
            </form>
            @endif
        @endif

        @if ($wo->inventory_fg_item_id && in_array($wo->status, ['in_progress', 'reconciliation']))
            @php($hasContinuousFeed = $processRecords->contains(fn ($record) => $record->continuous_feed_qty !== null))
            <div id="mixing-and-production" class="grid lg:grid-cols-2 gap-4 wo-action-area wo-module-section wo-module-mixing wo-module-filling-sealing">
                @if ($mixingRecords->isEmpty())
                    <form class="access-card p-4 space-y-3 wo-module-section wo-module-mixing" method="post" action="{{ route('forge.workorders.mixing', $wo) }}">
                        @csrf
                        <h2 class="font-bold">Step 2 · Mix PCM once</h2>
                        <p class="access-muted text-sm">Make one complete solution batch for this work order. Liquid loss is recovered into the same run; never store mixed solution as reusable warehouse stock.</p>
                        <div class="access-grid">
                            <label class="wo-field">Mixing method<select class="access-input" name="mixing_type" required><option value="">Choose method</option><option value="manual">Manual mixing</option><option value="mixer">Mixer machine</option></select><span class="access-muted font-normal">Select how the chemical and water solution is mixed.</span></label>
                            <label class="wo-field">Mix started<input class="access-input" name="started_at" type="datetime-local" required></label>
                            <label class="wo-field">Mix completed<input class="access-input" name="ended_at" type="datetime-local" required></label>
                            <label class="wo-field">Chemical quantity<input class="access-input" name="chemical_qty" type="number" min="0" step="0.001" placeholder="0.000" required></label>
                            <label class="wo-field">Water quantity<input class="access-input" name="water_qty" type="number" min="0" step="0.001" placeholder="0.000" required></label>
                            <label class="wo-field">Prepared PCM<input class="access-input" name="pcm_qty" type="number" min="0.001" step="0.001" placeholder="0.000" required></label>
                            <label class="wo-field">PCM unit<input class="access-input" name="pcm_uom" value="{{ $wo->reusablePcmItem?->unit ?? '' }}" placeholder="e.g. KG" maxlength="20" required><span class="access-muted font-normal">Use the prepared solution's unit, such as KG. If a PCM item is linked, use its unit.</span></label>
                            <label class="wo-field">Wastage<input class="access-input" name="wastage_qty" type="number" min="0" step="0.001" placeholder="0.000"></label>
                            <label class="wo-field">Wastage type<select class="access-input" name="wastage_type"><option value="">No wastage</option><option value="liquid">Liquid · recover into this order</option><option value="solid">Solid · record as waste</option></select></label>
                            <label class="wo-field">Time to fill machine (minutes)<input class="access-input" name="time_to_machine_minutes" type="number" min="0" step="1" placeholder="Minutes"></label>
                            <label class="wo-field">People involved<input class="access-input" name="manpower" type="number" min="1" placeholder="Number of people" required></label>
                        </div>
                        <label class="wo-field">Mixing notes<textarea class="access-input" name="notes" placeholder="Method or deviation"></textarea></label>
                        <button class="access-btn access-btn-primary">Save the one PCM mix</button>
                    </form>
                @else
                    <section class="access-card p-4 space-y-3 wo-module-section wo-module-mixing" aria-labelledby="single-mix-heading">
                        <h2 id="single-mix-heading" class="font-bold">Step 2 · PCM mix recorded</h2>
                        @foreach ($mixingRecords as $record)
                            <p class="text-sm">{{ ucfirst($record->mixing_type ?? 'manual') }} mixing · {{ $record->pcm_qty }} {{ $record->pcm_uom ?? $wo->reusablePcmItem?->unit ?? $wo->uom }} prepared · {{ $record->wastage_qty }} {{ $record->wastage_type ? ucfirst($record->wastage_type).' loss' : 'wastage' }} · {{ $record->manpower }} people · machine fill time {{ $record->time_to_machine_minutes ?? '—' }} min · {{ $record->started_at }} to {{ $record->ended_at }}</p>
                        @endforeach
                        <p class="access-muted text-sm">This work order has one continuous solution. Later production pauses are recorded as idle or breakdown time; no second mix is created.</p>
                    </section>
                @endif
                @if((int) $wo->workflow_version < 2 || $mixingRecords->isNotEmpty())
                <form class="access-card p-4 space-y-3 wo-module-section wo-module-filling-sealing" method="post" action="{{ route('forge.workorders.process', $wo) }}">
                    @csrf
                    <h2 class="font-bold">Step 3 · Continuous filling & sealing</h2>
                    <p class="access-muted text-sm">Choose filling or sealing and log the counted output. The solution feed is entered once for the whole order. If the line pauses, record the stop below instead of starting another pour.</p>
                    <div class="access-grid">
                        <label class="wo-field">Production step<select class="access-input" name="step" required><option value="filling">Filling</option><option value="sealing">Sealing</option></select></label>
                        <label class="wo-field">Machine mode<select class="access-input" name="machine_type" required><option value="manual">Manual</option><option value="automatic">Automatic</option></select></label>
                        <label class="wo-field">Machine<select class="access-input" name="machine_id"><option value="">Manual operation</option>@foreach ($machines->where('status', 'active') as $machine)<option value="{{ $machine->id }}">{{ $machine->name }}</option>@endforeach</select></label>
                        <label class="wo-field">Operation start<input class="access-input" name="started_at" type="datetime-local" required></label>
                        <label class="wo-field">Operation end<input class="access-input" name="ended_at" type="datetime-local" required></label>
                        @if (! $hasContinuousFeed)
                            <label class="wo-field">One continuous PCM feed ({{ $mixingRecords->first()?->pcm_uom ?? $wo->reusablePcmItem?->unit ?? $wo->uom }})<input class="access-input" name="continuous_feed_qty" type="number" min="0.001" step="0.001" placeholder="Enter total feed once" required><span class="access-muted font-normal">Full solution fed through the line, including recovered material reused on this order.</span></label>
                        @else
                            <p class="wo-field text-sm">Continuous PCM feed <strong>Already recorded once</strong><span class="access-muted font-normal">Do not split it into additional pours.</span></p>
                        @endif
                        @if ($recoveredPcmAvailable > 0)
                            <label class="wo-field">Recovered PCM reused on this order ({{ $mixingRecords->first()?->pcm_uom ?? $wo->reusablePcmItem?->unit ?? $wo->uom }})<input class="access-input" name="recovered_pcm_used_qty" type="number" min="0.001" max="{{ $recoveredPcmAvailable }}" step="0.001" placeholder="Available: {{ $recoveredPcmAvailable }}" required><span class="access-muted font-normal">This recovered solution is reused here and is never stored in the warehouse.</span></label>
                        @endif
                        <label class="wo-field">Good quantity<input class="access-input" name="good_qty" type="number" min="0" step="0.001" placeholder="0.000" required></label>
                        <label class="wo-field">Rejected quantity<input class="access-input" name="rejected_qty" type="number" min="0" step="0.001" placeholder="0.000"></label>
                        <label class="wo-field">Wastage quantity<input class="access-input" name="wastage_qty" type="number" min="0" step="0.001" placeholder="0.000"></label>
                        <label class="wo-field">Wastage reason<input class="access-input" name="wastage_reason" maxlength="255" placeholder="Required when wastage is above zero"></label>
                        <label class="wo-field">Recoverable PCM collected<input class="access-input" name="recovered_pcm_qty" type="number" min="0" step="0.001" placeholder="0.000"><span class="access-muted font-normal">Enter the part of this operation's wastage recovered for this same work order. Never stored as warehouse stock.</span></label>
                        <label class="wo-field">Power consumed (kWh)<input class="access-input" name="power_kwh" type="number" min="0" step="0.001" placeholder="Optional manual meter reading"></label>
                        <label class="wo-field">Idle time (minutes)<input class="access-input" name="idle_minutes" type="number" min="0" step="1" placeholder="0"></label>
                        <label class="wo-field">Why was the line idle?<input class="access-input" name="idle_reason" maxlength="255" placeholder="Required when idle time is above zero"></label>
                        <label class="wo-field">Breakdown time (minutes)<input class="access-input" name="breakdown_minutes" type="number" min="0" step="1" placeholder="0"></label>
                        <label class="wo-field">Breakdown reason<input class="access-input" name="breakdown_reason" maxlength="255" placeholder="Required when breakdown time is above zero"></label>
                        <label class="wo-field">People involved<input class="access-input" name="manpower" type="number" min="1" placeholder="Number of people" required></label>
                    </div>
                    <textarea class="access-input" name="notes" placeholder="Stop / process notes"></textarea>
                    <button class="access-btn access-btn-primary">Save this operation</button>
                </form>
                @else
                    <section class="access-card p-4 wo-module-section wo-module-filling-sealing">
                        <h2 class="font-bold">Complete PCM making first</h2>
                        <p class="access-muted text-sm mt-1">One recorded PCM mix is required before the continuous filling and sealing run.</p>
                        <a class="access-btn mt-3" href="{{ route('forge.modules.workorder', ['step' => 'mixing', 'workOrder' => $wo]) }}">Open Step 2 · PCM Making</a>
                    </section>
                @endif
            </div>
        @endif
        @if($mixingRecords->isNotEmpty() || $processRecords->isNotEmpty() || $qcRecords->isNotEmpty())
            <div class="access-card p-4 space-y-2 text-sm wo-module-section wo-module-mixing wo-module-filling-sealing">
                <h2 class="font-bold">Process history</h2>
                @foreach ($mixingRecords as $record)<p>PCM mix · {{ ucfirst($record->mixing_type ?? 'manual') }} method · {{ $record->pcm_qty }} {{ $record->pcm_uom ?? $wo->uom }} · {{ $record->wastage_qty }} {{ $record->wastage_type ? ucfirst($record->wastage_type).' waste' : 'waste' }} · {{ $record->manpower }} people · {{ $record->started_at }} → {{ $record->ended_at }} · {{ $record->notes ?: 'No notes' }}</p>@endforeach
                @foreach ($processRecords as $record)<p>Round {{ $record->cycle_no }} {{ ucfirst($record->step) }} · {{ ucfirst($record->machine_type ?? 'manual') }}{{ $record->machine_id ? ' · machine #'.$record->machine_id : '' }} · {{ $record->good_qty }} good / {{ $record->rejected_qty }} rejected · {{ $record->power_kwh ?? '—' }} kWh · idle {{ $record->idle_minutes }}m{{ $record->idle_reason ? ' ('.$record->idle_reason.')' : '' }} · breakdown {{ $record->breakdown_minutes }}m{{ $record->breakdown_reason ? ' ('.$record->breakdown_reason.')' : '' }} · waste {{ $record->wastage_qty }}{{ $record->wastage_reason ? ' ('.$record->wastage_reason.')' : '' }} · recovered {{ $record->recovered_pcm_qty ?? 0 }} · reused {{ $record->recovered_pcm_used_qty ?? 0 }} · {{ $record->started_at }} → {{ $record->ended_at }}</p>@endforeach
                @foreach ($qcRecords as $record)<p>Round {{ $loop->iteration }} QC · {{ ucfirst($record->result) }} · {{ $record->accepted_qty }} accepted / {{ $record->rejected_qty }} rejected / {{ $record->rework_qty }} rework {{ $wo->uom }} · PCM recovered {{ $record->recovered_pcm_qty }} · leak {{ $record->leak_test ?? '—' }} / pressure {{ $record->pressure_test ?? '—' }} / physical {{ $record->physical_check ?? '—' }} · {{ $record->released_at ? 'approved '.$record->released_at : 'awaiting approval' }} · {{ $record->specification_results ?: 'No notes' }}</p>@endforeach
            </div>
        @endif

        <div class="access-card p-5 wo-module-section wo-module-filling-sealing">
            <h2 class="font-bold mb-3">Job Cards</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>#</th><th>Operation</th><th>Machine</th><th>Operator</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($wo->jobCards as $card)
                            <tr>
                                <td>{{ $card->sequence }}</td>
                                <td>{{ $card->operation_name }}</td>
                                <td>{{ $card->machine?->name ?? '—' }}</td>
                                <td>{{ $card->operator?->name ?? '—' }}</td>
                                <td><span class="access-chip">{{ str($card->status)->headline() }}</span></td>
                                <td class="flex gap-2">
                                    @if ($card->status === 'pending')
                                        <form method="post" action="{{ route('forge.job-cards.start', $card) }}" class="flex flex-wrap gap-2 items-center">
                                            {{ csrf_field() }}
                                            <select class="access-input" name="machine_id" aria-label="Machine for {{ $card->operation_name }}">
                                                <option value="">Manual operation</option>
                                                @foreach ($machines->where('status', 'active') as $machine)
                                                    <option value="{{ $machine->id }}" @selected($card->machine_id === $machine->id)>{{ $machine->name }}</option>
                                                @endforeach
                                            </select>
                                            <button class="access-btn">Start</button>
                                        </form>
                                    @endif
                                    @if ($card->status === 'started')
                                        <form method="post" action="{{ route('forge.job-cards.pause', $card) }}">{{ csrf_field() }}<button class="access-btn">Pause</button></form>
                                        <form method="post" action="{{ route('forge.job-cards.complete', $card) }}">{{ csrf_field() }}<button class="access-btn access-btn-primary">Complete</button></form>
                                    @endif
                                    @if ($card->status === 'paused')
                                        <form method="post" action="{{ route('forge.job-cards.resume', $card) }}">{{ csrf_field() }}<button class="access-btn">Resume</button></form>
                                        <form method="post" action="{{ route('forge.job-cards.complete', $card) }}">{{ csrf_field() }}<button class="access-btn access-btn-primary">Complete</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="access-muted text-center py-6">No job cards — this work order has no routing attached.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if (in_array($wo->status, ['in_progress', 'reconciliation']) && ((int) $wo->workflow_version < 2
            || $processRecords->contains(fn ($record) => $record->step === 'sealing' && (int) $record->cycle_no === (int) $wo->rework_generation + 1)))
            <form class="access-card p-4 space-y-3 wo-module-section wo-module-filling-sealing" method="post" action="{{ route('forge.workorders.record-production', $wo) }}">
                @csrf
                <h2 class="font-bold">Record Production</h2>
                <p class="access-muted text-sm">Record the counted output for this work order. Good, rework and rejected quantities must reflect distinct units; link a job card when output belongs to a specific operation.</p>
                <div class="access-grid">
                    <label class="wo-field">Job card (optional)<select class="access-input" name="job_card_id">
                        <option value="">No specific job card</option>
                        @foreach ($wo->jobCards as $card)
                            <option value="{{ $card->id }}">{{ $card->operation_name }}</option>
                        @endforeach
                    </select><span class="access-muted font-normal">Connect this count to its routing operation when applicable.</span></label>
                    <label class="wo-field">Good quantity<input class="access-input" name="good_qty" type="number" step="0.001" min="0" placeholder="Good qty" required><span class="access-muted font-normal">Completed units awaiting quality/reconciliation checks.</span></label>
                    <label class="wo-field">Rework quantity<input class="access-input" name="rework_qty" type="number" step="0.001" min="0" placeholder="Rework qty"><span class="access-muted font-normal">Units that need correction before final disposition.</span></label>
                    <label class="wo-field">Rejected quantity<input class="access-input" name="rejected_qty" type="number" step="0.001" min="0" placeholder="Rejected qty"><span class="access-muted font-normal">Units that cannot be accepted as finished output.</span></label>
                </div>
                <button class="access-btn access-btn-primary">Record</button>
            </form>
        @endif

        <div class="access-card p-5 wo-module-section wo-module-filling-sealing">
            <h2 class="font-bold mb-3">Production Entries</h2>
            <div style="overflow-x:auto">
                <table class="access-table">
                    <thead><tr><th>Good</th><th>Rework</th><th>Rejected</th><th>Status</th><th>Recorded By</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($wo->productionEntries as $entry)
                            <tr>
                                <td>{{ $entry->good_qty }}</td>
                                <td>{{ $entry->rework_qty }}</td>
                                <td>{{ $entry->rejected_qty }}</td>
                                <td><span class="access-chip">{{ str($entry->status)->headline() }}</span></td>
                                <td>{{ $entry->recorder?->name }}</td>
                                <td>
                                    @if ($entry->status === 'draft')
                                        <form method="post" action="{{ route('forge.production.approve', $entry) }}">{{ csrf_field() }}<button class="access-btn">Approve</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="access-muted text-center py-6">No production entries yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-4 wo-module-section wo-module-materials">
            <section class="access-card p-5">
                <h2 class="font-bold mb-3">Material Issues</h2>
                <div class="space-y-2">
                    @forelse ($wo->materialIssues as $issue)
                        <div class="border rounded p-3 text-sm" style="border-color:#dfe7e2">
                            {{ $issue->item_name }} ({{ $issue->item_code }}) — {{ $issue->qty }} {{ $issue->uom }}
                            <span class="access-muted">· {{ $issue->movement_type }} · lot {{ $issue->lot_number ?? '—' }}</span>
                        </div>
                    @empty
                        <p class="access-muted text-sm">No material movements yet.</p>
                    @endforelse
                </div>
            </section>
            <section class="access-card p-5">
                <h2 class="font-bold mb-3">Quality Holds</h2>
                <div class="space-y-2">
                    @forelse ($wo->qualityHolds as $hold)
                        <div class="border rounded p-3 text-sm" style="border-color:#dfe7e2">
                            {{ $hold->checkpoint }} — <span class="access-chip">{{ $hold->status }}</span>
                            <span class="access-muted">result: {{ $hold->result ?? '—' }}</span>
                        </div>
                    @empty
                        <p class="access-muted text-sm">No IPQC checkpoints recorded yet.</p>
                    @endforelse
                </div>
            </section>
        </div>

        @if ($wo->batch)
            <div class="access-card p-5 wo-module-section wo-module-consumption-fg">
                <h2 class="font-bold mb-2">Finished Batch</h2>
                <a class="access-btn access-btn-primary" href="{{ route('forge.batches.show', $wo->batch) }}">
                    View genealogy for batch {{ $wo->batch->batch_number }}
                </a>
            </div>
        @endif
    </div>
</x-app-layout>
