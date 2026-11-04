<?php

namespace App\Http\Controllers\Forge;

use App\Http\Controllers\Controller;
use App\Models\Forge\ProductionFgMapping;
use App\Models\Inventory\InventoryItem;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Models\Tan90\MasterData\Warehouse;
use App\Services\Access\AccessControlService;
use App\Support\SiteLocation;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductionMappingController extends Controller
{
    public function index(Request $request, AccessControlService $access)
    {
        abort_unless($access->can($request->user(), 'forge.plan.view'), 403);
        $site = SiteLocation::forUser($request->user());

        return view('forge.mappings.index', [
            'mappings' => ProductionFgMapping::query()->when($site !== null, fn ($q) => $q->where('site_id', $site))->latest()->get(),
            'locations' => SiteLocation::visibleLocations(),
            'goods' => FinishedGood::active()->approvalStatus('approved')->orderBy('name')->get(),
            'items' => InventoryItem::where('active', true)->whereIn('item_type', ['fg', 'rm'])->orderBy('code')->get(),
            'warehouses' => Warehouse::where('status', 'active')->whereIn('warehouse_type', ['Raw Material', 'Finished Goods'])->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request, AccessControlService $access)
    {
        abort_unless($access->can($request->user(), 'forge.plan.approve'), 403);
        $data = $request->validate([
            'site_id' => ['required', 'exists:tan90_locations,id'],
            'finished_good_id' => ['required', 'exists:tan90_finished_goods,id'],
            'inventory_fg_item_id' => ['required', 'exists:inventory_items,id'],
            'rm_warehouse_id' => ['required', 'exists:tan90_warehouses,id'],
            'fg_warehouse_id' => ['required', 'exists:tan90_warehouses,id'],
            'reusable_pcm_item_id' => ['nullable', 'exists:inventory_items,id'],
        ]);
        SiteLocation::assertCanAccessSiteRecord($request->user(), (int) $data['site_id']);
        $fg = InventoryItem::findOrFail($data['inventory_fg_item_id']);
        if (! $fg->active || $fg->item_type !== 'fg' || $fg->bomLines()->doesntExist()
            || ! FinishedGood::active()->approvalStatus('approved')->whereKey($data['finished_good_id'])->exists()) {
            throw ValidationException::withMessages(['inventory_fg_item_id' => 'Choose an approved FG and active Inventory FG SKU with RM/PM recipe lines.']);
        }
        foreach (['rm_warehouse_id' => 'Raw Material', 'fg_warehouse_id' => 'Finished Goods'] as $key => $type) {
            if (! Warehouse::whereKey($data[$key])->where('tan90_location_id', $data['site_id'])->where('status', 'active')->where('warehouse_type', $type)->exists()) {
                throw ValidationException::withMessages([$key => "Choose an active {$type} warehouse at this site."]);
            }
        }
        if (! empty($data['reusable_pcm_item_id']) && ! InventoryItem::whereKey($data['reusable_pcm_item_id'])->where('item_type', 'rm')->where('active', true)->exists()) {
            throw ValidationException::withMessages(['reusable_pcm_item_id' => 'Reusable PCM must be an active RM SKU.']);
        }
        ProductionFgMapping::updateOrCreate(
            ['site_id' => $data['site_id'], 'finished_good_id' => $data['finished_good_id']],
            $data + ['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]
        );

        return back()->with('status', 'Site FG SKU and warehouse mapping approved.');
    }
}
