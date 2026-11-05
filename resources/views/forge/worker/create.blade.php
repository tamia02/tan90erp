<x-app-layout>
@include('forge.worker._style')
<div class="pw-shell"><header class="pw-head pw-row"><div><div class="pw-kicker">Production · Work Orders</div><h1>Create Work Order</h1></div><a class="pw-btn" href="{{ route('forge.workorders.index') }}">Work Orders</a></header>
@include('forge.worker._notices')
@if($plan)<div class="pw-order">Linked plan: <strong>{{ $plan->plan_number }}</strong>@if($plan->sourceOrderLine?->order) · {{ $plan->sourceOrderLine->order->order_number }}@endif</div>@endif
<form method="post" action="{{ route('forge.workorders.store') }}" class="pw-card pw-stack"
 x-data="{items:@js($eligible),site:@js((string)old('site_id',$plan?->site_id??($locations->count()===1?$locations->first()->id:''))),fg:@js((string)old('finished_good_id',$plan?->finished_good_id??'')),definition:@js(old('definition','')),get products(){return this.items.filter(i=>String(i.site_id)===String(this.site))},get definitions(){return this.products.find(i=>String(i.fg_id)===String(this.fg))?.definitions||[]}}"
 x-effect="if(definitions.length===1){definition=definitions[0].key}else if(!definitions.some(d=>d.key===definition)){definition=''}">
@csrf<input type="hidden" name="worker_create" value="1"><input type="hidden" name="creation_key" value="{{ $creationKey }}">@if($plan)<input type="hidden" name="production_plan_id" value="{{ $plan->id }}"><input type="hidden" name="site_id" value="{{ $plan->site_id }}"><input type="hidden" name="finished_good_id" value="{{ $plan->finished_good_id }}">@endif
<div class="pw-grid">
<label class="pw-field">Production Site<select class="pw-input" name="site_id" x-model="site" @change="fg=''" required @disabled($plan)><option value="">Select site</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>@error('site_id')<span class="pw-field-error">{{ $message }}</span>@enderror</label>
<label class="pw-field">Finished Good (FG)<select class="pw-input" name="finished_good_id" x-model="fg" required @disabled($plan)><option value="">Select product</option><template x-for="item in products" :key="item.fg_id"><option :value="String(item.fg_id)" x-text="item.name"></option></template></select>@error('finished_good_id')<span class="pw-field-error">{{ $message }}</span>@enderror</label>
<label class="pw-field">Quantity<input class="pw-input" name="target_qty" type="number" step="0.001" min="0.001" value="{{ old('target_qty',$remainingQty) }}" required></label>
<label class="pw-field">Required Date<input class="pw-input" name="due_date" type="date" value="{{ old('due_date',$plan?->due_date?->format('Y-m-d')??today()->format('Y-m-d')) }}" required></label>
<label class="pw-field">Batch Reference (optional)<input class="pw-input" name="batch_number" value="{{ old('batch_number') }}" maxlength="100"></label>
<label class="pw-field" x-show="definitions.length>1" x-cloak>Approved Recipe / BOM<select class="pw-input" name="definition" x-model="definition" :required="definitions.length>1"><option value="">Select approved version</option><template x-for="d in definitions" :key="d.key"><option :value="d.key" x-text="d.code+' ('+d.type+')'"></option></template></select></label>
</div><p class="pw-muted" x-show="definitions.length===1" x-text="'Recipe / BOM: '+(definitions[0]?.code||'')"></p><p class="pw-muted">Recipe, unit and warehouses follow the approved product mapping.</p><div><button class="pw-btn pw-primary" :disabled="!fg||!site||!definition">Create Draft Work Order</button></div>
</form>
@if(count($setup))<details class="pw-card"><summary>Setup Required · {{ count($setup) }} products</summary><p class="pw-muted mt-2">These products need an approved recipe and site/warehouse mapping before production.</p><ul class="pw-muted list-disc pl-5 mt-2">@foreach($setup as $name)<li>{{ $name }}</li>@endforeach</ul><a class="pw-btn mt-3" href="{{ route('forge.mappings.index') }}">Product Mappings</a></details>@endif
</div>
</x-app-layout>
