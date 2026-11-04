        @if($canCreateWorkOrder)
        <form id="create-work-order" class="access-card p-4 space-y-3" method="post" action="{{ route('forge.workorders.store') }}">
            @csrf
            <h2 class="font-bold">Create Work Order from an Approved Plan</h2>
            <p class="access-muted text-sm">A work order is the executable shop-floor job for part of a production plan. Keep product, unit, site and quantity aligned with that plan so material, output and stock remain traceable.</p>
            <div class="access-grid">
                <label class="block text-sm font-semibold">Production plan
                    <select class="access-input mt-1 w-full" name="production_plan_id" id="plan-select" required>
                        <option value="">Approved production plan</option>
                        @foreach ($plans as $plan)
                            @php($remainingQty = max(0, round((float) $plan->target_qty - (float) $plan->work_orders_sum_target_qty, 3)))
                            @php($planReady = $plan->site_id && $plan->finishedGood && strcasecmp(trim((string) $plan->uom), trim((string) $plan->finishedGood->uom)) === 0 && collect($mappingOptions)->contains(fn ($mapping) => (int) $mapping['site_id'] === (int) $plan->site_id && (int) $mapping['finished_good_id'] === (int) $plan->finished_good_id))
                            <option value="{{ $plan->id }}" data-finished-good="{{ $plan->finished_good_id }}" data-uom="{{ $plan->uom }}" data-site="{{ $plan->site_id }}" data-qty="{{ $remainingQty }}" @selected(old('production_plan_id', request('plan')) == $plan->id) @disabled($remainingQty <= 0 || !$planReady)>
                                {{ $plan->plan_number }} — {{ $plan->finishedGood?->name }} · {{ number_format($remainingQty, 3) }} {{ $plan->uom }} remaining @if(!$planReady) · repair plan first @endif @if($plan->source_order_line_id) · Sales line #{{ $plan->source_order_line_id }} @endif
                            </option>
                        @endforeach
                    </select>
                    <span class="access-muted block text-xs mt-1">Sets the finished good, site, unit and maximum total quantity for its work orders.</span>
                    @error('production_plan_id')<span class="text-red-700 text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold">Batch number (optional)
                    <input class="access-input mt-1 w-full" name="batch_number" value="{{ old('batch_number') }}" placeholder="Batch number (optional)">
                    <span class="access-muted block text-xs mt-1">Plant batch reference; leave blank to use the work-order number.</span>
                </label>
                <label class="block text-sm font-semibold">Target quantity
                    <input class="access-input mt-1 w-full" name="target_qty" type="number" step="0.001" min="0.001" value="{{ old('target_qty') }}" placeholder="Target quantity" required>
                    <span class="access-muted block text-xs mt-1">Quantity for this work order; all work orders together cannot exceed the plan.</span>
                    @error('target_qty')<span class="text-red-700 text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold">Unit of measure
                    <input class="access-input mt-1 w-full" name="uom" value="{{ old('uom') }}" placeholder="UOM" required>
                    <span class="access-muted block text-xs mt-1">Must match the production plan and finished-good inventory unit.</span>
                    @error('uom')<span class="text-red-700 text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold">Plant (optional)
                    <input class="access-input mt-1 w-full" name="plant" value="{{ old('plant') }}" placeholder="Plant">
                    <span class="access-muted block text-xs mt-1">Display label for a production area; site selection controls stock access.</span>
                </label>
            </div>
            <h3 class="font-semibold text-sm pt-2">Field guide</h3>
            <p class="access-muted text-sm">BOM / recipe defines the approved material formula and must match the finished good. Inventory FG SKU is the exact stock item received after QC. RM/PM warehouse is where issuing reduces raw-material stock; FG warehouse is where released output is received. Both warehouses must belong to the selected site. The PCM formula item is a reference for its unit and FIFO product handling; recovered mixed solution stays on this work order and never enters warehouse stock.</p>
            <div class="access-grid">
                <label class="block text-sm font-semibold">Approved recipe (optional if BOM selected)
                    <select class="access-input mt-1 w-full" name="recipe_id" id="recipe-select"><option value="">Approved recipe (or choose BOM)</option>
                        @foreach ($approvedRecipes as $recipe)<option value="{{ $recipe->id }}" data-fg="{{ $recipe->tan90_finished_good_id }}" @selected(old('recipe_id') == $recipe->id)>{{ $recipe->code }}</option>@endforeach
                    </select><span class="access-muted block text-xs mt-1">Defines the material quantities for each unit of finished good.</span>
                    @error('recipe_id')<span class="text-red-700 text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold">Approved BOM (optional if recipe selected)
                    <select class="access-input mt-1 w-full" name="bom_id" id="bom-select"><option value="">Approved BOM (or choose recipe)</option>
                        @foreach ($approvedBoms as $bom)<option value="{{ $bom->id }}" data-fg="{{ $bom->tan90_finished_good_id }}" @selected(old('bom_id') == $bom->id)>{{ $bom->code }}</option>@endforeach
                    </select><span class="access-muted block text-xs mt-1">Alternate approved product definition; choose either a BOM or a recipe.</span>
                    @error('bom_id')<span class="text-red-700 text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold">Inventory FG SKU
                    <select class="access-input mt-1 w-full" name="inventory_fg_item_id" required><option value="">Inventory FG SKU</option>
                        @foreach ($inventoryFgItems as $item)<option value="{{ $item->id }}" @selected(old('inventory_fg_item_id') == $item->id)>{{ $item->code ?: $item->id }} — {{ $item->safeDisplayName() }} ({{ $item->unit }})</option>@endforeach
                    </select><span class="access-muted block text-xs mt-1">Exact finished-good stock card used for batch receipt and order allocation.</span>
                    @error('inventory_fg_item_id')<span class="text-red-700 text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold">Production site
                    <select class="access-input mt-1 w-full" name="site_id" id="site-select" required><option value="">Production site</option>
                        @foreach ($locations as $location)<option value="{{ $location->id }}" @selected(old('site_id') == $location->id)>{{ $location->name }}</option>@endforeach
                    </select><span class="access-muted block text-xs mt-1">Must match the selected plan and both warehouses.</span>
                    @error('site_id')<span class="text-red-700 text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold">RM/PM warehouse
                    <select class="access-input mt-1 w-full" name="rm_warehouse_id" id="rm-warehouse-select" required><option value="">RM/PM warehouse</option>
                        @foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}" data-site="{{ $warehouse->tan90_location_id }}" data-type="{{ $warehouse->warehouse_type }}" @selected(old('rm_warehouse_id') == $warehouse->id)>{{ $warehouse->code }} — {{ $warehouse->name }}</option>@endforeach
                    </select><span class="access-muted block text-xs mt-1">Raw and packaging stock is reserved and issued from this site warehouse.</span>
                    @error('rm_warehouse_id')<span class="text-red-700 text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold">FG warehouse
                    <select class="access-input mt-1 w-full" name="fg_warehouse_id" id="fg-warehouse-select" required><option value="">FG warehouse</option>
                        @foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}" data-site="{{ $warehouse->tan90_location_id }}" data-type="{{ $warehouse->warehouse_type }}" @selected(old('fg_warehouse_id') == $warehouse->id)>{{ $warehouse->code }} — {{ $warehouse->name }}</option>@endforeach
                    </select><span class="access-muted block text-xs mt-1">Receives the released batch and supplies stock for sales allocation.</span>
                    @error('fg_warehouse_id')<span class="text-red-700 text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold">PCM formula item (optional)
                    <select class="access-input mt-1 w-full" name="reusable_pcm_item_id"><option value="">PCM formula item reference</option>
                        @foreach ($reusablePcmItems as $item)<option value="{{ $item->id }}" @selected(old('reusable_pcm_item_id') == $item->id)>{{ $item->code ?: $item->id }} — {{ $item->safeDisplayName() }}</option>@endforeach
                    </select><span class="access-muted block text-xs mt-1">Reference only: sets the PCM unit and PCM product handling. Recovered solution is reused within the same work order, never stored as a warehouse SKU.</span>
                    @error('reusable_pcm_item_id')<span class="text-red-700 text-xs">{{ $message }}</span>@enderror
                </label>
            </div>
            <input type="hidden" name="finished_good_id" id="finished-good-input" value="{{ old('finished_good_id', $plans->firstWhere('id', old('production_plan_id'))?->finished_good_id) }}">
            <button class="access-btn access-btn-primary">Create Work Order</button>
        </form>
        @endif
