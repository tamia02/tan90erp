<?php

namespace Database\Seeders;

use App\Http\Controllers\Flow\CustomerOrderController;
use App\Http\Controllers\Flow\InventoryController;
use App\Http\Controllers\Forge\ProductionOperationController;
use App\Http\Controllers\Forge\ProductionPlanController;
use App\Http\Controllers\Forge\WorkOrderController;
use App\Models\Flow\CustomerOrder;
use App\Models\Flow\InventoryLot;
use App\Models\Forge\ProductionFgMapping;
use App\Models\Forge\ProductionPlan;
use App\Models\Forge\WorkOrder;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Models\Tan90\BomRecipeCosting\Recipe;
use App\Models\Tan90\MasterData\Bin;
use App\Models\Tan90\MasterData\BusinessUnit;
use App\Models\Tan90\MasterData\Location;
use App\Models\Tan90\MasterData\Plant;
use App\Models\Tan90\MasterData\Rack;
use App\Models\Tan90\MasterData\Shelf;
use App\Models\Tan90\MasterData\Warehouse;
use App\Models\Tan90\MasterData\WarehouseZone;
use App\Models\User;
use App\Services\Forge\MaterialRequestService;
use App\Services\Forge\WorkOrderService;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Isolated Stage-only Store Manager order through finished-goods acceptance. */
class StageOrderToFgDemoSeeder extends Seeder
{
    private const CUSTOMER = 'STAGE DEMO ONLY — Store Manager Order to FG';

    public function run(): void
    {
        if (! app()->environment('staging') || DB::connection()->getDatabaseName() !== 'u989642491_tan90uu') {
            throw new RuntimeException('This demo transaction may run only on stage.tan90.xyz using its isolated Stage database.');
        }

        if (CustomerOrder::where('customer_name', self::CUSTOMER)->exists()) {
            throw new RuntimeException('The Stage order-to-FG demo already exists; refusing to create a duplicate transaction.');
        }

        DB::transaction(function (): void {
            $actor = User::where('email', 'stage-demo-storemanager@tan90.demo')->where('is_active', true)->firstOrFail();
            $inventoryReceiver = User::where('email', 'superadmin@tan90.demo')->where('is_active', true)->firstOrFail();
            Auth::setUser($actor);
            [$site, $rmWarehouse, $fgWarehouse, $finishedGood, $recipe, $mapping, $bin, $materials] = $this->fixture($actor);

            // Exercise the same authorized controllers used by the Store Manager screens.
            $this->invokeController($actor, CustomerOrderController::class, 'store', '/flow/orders', [
                'customer_name' => self::CUSTOMER,
                'destination' => 'Isolated Stage demo site',
                'requested_date' => today()->addDays(2)->toDateString(),
                'site_id' => $site->id,
                'fg_warehouse_id' => $fgWarehouse->id,
            ]);
            $order = CustomerOrder::where('customer_name', self::CUSTOMER)->latest('id')->firstOrFail();
            $this->invokeController($actor, CustomerOrderController::class, 'addLine', '/flow/orders/'.$order->id.'/lines', [
                'finished_good_id' => $finishedGood->id,
                'qty_ordered' => 2,
                'uom' => 'EA',
            ], [$order]);
            $this->invokeController($actor, CustomerOrderController::class, 'validateOrder', '/flow/orders/'.$order->id.'/validate', [], [$order]);
            $this->invokeController($actor, CustomerOrderController::class, 'release', '/flow/orders/'.$order->id.'/release', [], [$order]);

            $line = $order->lines()->firstOrFail();
            $plan = ProductionPlan::where('source_order_line_id', $line->id)->firstOrFail();
            $this->invokeController($actor, ProductionPlanController::class, 'approve', '/forge/plans/'.$plan->id.'/approve', [], [$plan]);
            $plan->refresh();

            $this->invokeController($actor, WorkOrderController::class, 'store', '/forge/workorders', [
                'production_plan_id' => $plan->id,
                'finished_good_id' => $finishedGood->id,
                'recipe_id' => $recipe->id,
                'target_qty' => 2,
                'uom' => 'EA',
                'site_id' => $site->id,
                'rm_warehouse_id' => $rmWarehouse->id,
                'fg_warehouse_id' => $fgWarehouse->id,
                'inventory_fg_item_id' => $mapping->inventory_fg_item_id,
            ]);
            $workOrder = WorkOrder::where('production_plan_id', $plan->id)->latest('id')->firstOrFail();
            $work = app(WorkOrderService::class);
            $work->release($workOrder, $actor);

            $requestService = app(MaterialRequestService::class);
            $requestId = $requestService->request($workOrder->fresh(), $materials, $actor);
            $requestService->approveAndIssue($workOrder->fresh(), $requestId, [
                'handover_started_at' => now()->subMinutes(12),
                'handover_ended_at' => now()->subMinutes(10),
                'manpower' => 2,
            ], $actor);
            $workOrder->refresh();
            if ($workOrder->status === 'material_issued') {
                $work->startProgress($workOrder);
            }

            $operations = app(ProductionOperationController::class);
            $this->invokeController($actor, ProductionOperationController::class, 'mixing', '/forge/workorders/'.$workOrder->id.'/mixing', [
                'mixing_type' => 'manual',
                'started_at' => now()->subMinutes(9)->toDateTimeString(),
                'ended_at' => now()->subMinutes(8)->toDateTimeString(),
                'chemical_qty' => 0.8,
                'water_qty' => 0.2,
                'pcm_qty' => 1.0,
                'pcm_uom' => 'KG',
                'wastage_qty' => 0,
                'manpower' => 2,
            ], [$workOrder]);
            foreach (['filling', 'sealing'] as $step) {
                $operation = [
                    'step' => $step,
                    'machine_type' => 'manual',
                    'started_at' => now()->subMinutes(7)->toDateTimeString(),
                    'ended_at' => now()->subMinutes(6)->toDateTimeString(),
                    'good_qty' => 2,
                    'rejected_qty' => 0,
                    'wastage_qty' => 0,
                    'manpower' => 2,
                ];
                if ($step === 'filling') {
                    $operation['continuous_feed_qty'] = 1.0;
                }
                $this->invokeController($actor, ProductionOperationController::class, 'process', '/forge/workorders/'.$workOrder->id.'/process', [
                    ...$operation,
                ], [$workOrder]);
            }

            $entry = $work->recordProduction($workOrder->fresh(), ['good_qty' => 2], $actor);
            $work->approveProduction($entry, $actor);
            $work->sendToFinalQc($workOrder->fresh());
            $qc = $work->recordFinalQc($workOrder->fresh(), [
                'accepted_qty' => 2,
                'rejected_qty' => 0,
                'rework_qty' => 0,
                'result' => 'released',
                'leak_test' => 'pass',
                'pressure_test' => 'pass',
                'recovered_pcm_qty' => 0,
                'started_at' => now()->subMinutes(5),
                'ended_at' => now()->subMinutes(4),
                'manpower' => 2,
            ], $actor);
            $work->releaseFinalQc($qc, $actor);

            $workOrder->refresh();
            $batch = $workOrder->batch;
            if ($workOrder->status !== 'released_to_fg' || ! $batch) {
                throw new RuntimeException('Final QC did not automatically reconcile consumption and post the accepted FG batch.');
            }

            // Store Manager owns production and bin putaway. The separate Stage
            // Super Admin performs the guarded Flow receipt, matching the
            // current permission boundary without granting Store Managers a
            // new inventory-receive privilege.
            $this->invokeController($inventoryReceiver, InventoryController::class, 'receive', '/flow/inventory/'.$batch->id.'/receive', [], [$batch]);
            $lot = InventoryLot::where('forge_batch_id', $batch->id)->firstOrFail();
            $this->invokeController($actor, InventoryController::class, 'putaway', '/flow/inventory/'.$lot->id.'/putaway', [
                'bin_id' => $bin->id,
                'putaway_started_at' => now()->subMinute(),
                'putaway_ended_at' => now(),
                'putaway_manpower' => 2,
            ], [$lot]);

            $workOrder->refresh();
            $order->refresh();
            $line->refresh();
            $lot->refresh();
            if ($workOrder->status !== 'released_to_fg' || $lot->status !== 'available'
                || (float) $line->qty_allocated !== 2.0 || $order->status !== 'allocated') {
                throw new RuntimeException('Stage order-to-FG verification failed; transaction rolled back.');
            }

            $this->command?->info(json_encode([
                'environment' => app()->environment(),
                'site' => $site->code,
                'order' => $order->order_number,
                'order_status' => $order->status,
                'production_plan' => $plan->plan_number,
                'work_order' => $workOrder->wo_number,
                'work_order_status' => $workOrder->status,
                'batch' => $batch->batch_number,
                'finished_goods_lot' => $lot->lot_number,
                'finished_goods_accepted' => (float) $lot->qty_received,
                'allocated_to_order' => (float) $line->qty_allocated,
                'shipment_created' => false,
            ], JSON_UNESCAPED_SLASHES));
        });
    }

    /** @return array{Location, Warehouse, Warehouse, FinishedGood, Recipe, ProductionFgMapping, Bin, array<int, array{inventory_item_id:int, qty:float}>} */
    private function fixture(User $actor): array
    {
        $site = Location::updateOrCreate(['code' => 'SITE-STAGE-DEMO-O2FG'], [
            'name' => 'Stage Demo — Order to Finished Goods', 'type' => 'Manufacturing',
            'state' => 'Stage Demo', 'city' => 'Stage Demo', 'status' => 'active', 'approval_status' => 'approved',
        ]);
        $businessUnit = BusinessUnit::where('status', 'active')->where('approval_status', 'approved')->orderBy('id')->firstOrFail();
        $plant = Plant::updateOrCreate(['code' => 'PLANT-STAGE-DEMO-O2FG'], [
            'name' => 'Stage Demo Production Plant', 'tan90_business_unit_id' => $businessUnit->id,
            'tan90_location_id' => $site->id, 'status' => 'active', 'approval_status' => 'approved',
        ]);
        $rmWarehouse = Warehouse::updateOrCreate(['code' => 'WH-STAGE-DEMO-O2FG-RM'], [
            'name' => 'Stage Demo RM and PM Warehouse', 'tan90_plant_id' => $plant->id,
            'tan90_location_id' => $site->id, 'warehouse_type' => 'Raw Material',
            'status' => 'active', 'approval_status' => 'approved',
        ]);
        $fgWarehouse = Warehouse::updateOrCreate(['code' => 'WH-STAGE-DEMO-O2FG-FG'], [
            'name' => 'Stage Demo Finished Goods Warehouse', 'tan90_plant_id' => $plant->id,
            'tan90_location_id' => $site->id, 'warehouse_type' => 'Finished Goods',
            'status' => 'active', 'approval_status' => 'approved',
        ]);

        $finishedGood = FinishedGood::where('code', 'FG-PCM500-BLUE')->active()->approvalStatus('approved')->firstOrFail();
        $recipe = Recipe::where('tan90_finished_good_id', $finishedGood->id)->where('code', 'RCP-PCM500-BLUE')
            ->where('status', 'active')->where('approval_status', 'approved')->firstOrFail();

        $fgItem = $this->item('STG-FG-PCM500-O2FG', 'Stage Demo 500gm PCM Pouch', 'fg', 'EA', $actor);
        $components = [
            ['STG-RM-MGNO3-O2FG', 'Stage Demo PCM Blend A', 'rm', 'KG', 0.190],
            ['STG-RM-KCL-O2FG', 'Stage Demo PCM Blend B', 'rm', 'KG', 0.110],
            ['STG-RM-NAF-O2FG', 'Stage Demo PCM Blend C', 'rm', 'KG', 0.075],
            ['STG-RM-STAB-O2FG', 'Stage Demo Stabilizer', 'rm', 'KG', 0.025],
            ['STG-RM-WATER-O2FG', 'Stage Demo Process Water', 'rm', 'L', 0.100],
            ['STG-PM-POUCH-O2FG', 'Stage Demo Blue Pouch Film', 'pm', 'EA', 1.000],
        ];
        $materials = [];
        foreach ($components as [$code, $name, $type, $unit, $qty]) {
            $component = $this->item($code, $name, $type, $unit, $actor);
            $fgItem->bomLines()->updateOrCreate(['component_item_id' => $component->id], ['qty_per_unit' => $qty]);
            InventoryMovement::firstOrCreate(['source_key' => 'stage-o2fg-opening:'.$code], [
                'item_id' => $component->id, 'type' => 'adjust_in', 'qty' => 10,
                'location_id' => $site->id, 'warehouse_id' => $rmWarehouse->id,
                'note' => 'Synthetic Stage-only acceptance stock', 'created_by' => $actor->id,
            ]);
            $materials[] = ['inventory_item_id' => (int) $component->id, 'qty' => $qty * 2];
        }

        $mapping = ProductionFgMapping::updateOrCreate(['site_id' => $site->id, 'finished_good_id' => $finishedGood->id], [
            'inventory_fg_item_id' => $fgItem->id,
            'rm_warehouse_id' => $rmWarehouse->id,
            'fg_warehouse_id' => $fgWarehouse->id,
            'status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(),
        ]);
        $bin = $this->fgBin($fgWarehouse);

        return [$site, $rmWarehouse, $fgWarehouse, $finishedGood, $recipe, $mapping, $bin, $materials];
    }

    private function item(string $code, string $name, string $type, string $unit, User $actor): InventoryItem
    {
        return InventoryItem::updateOrCreate(['code' => $code], [
            'sku' => $code, 'name' => $name, 'item_type' => $type, 'unit' => $unit,
            'opening_qty' => 0, 'active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
    }

    private function fgBin(Warehouse $warehouse): Bin
    {
        $zone = WarehouseZone::updateOrCreate(['code' => 'STG-O2FG-ZONE'], [
            'name' => 'Stage Demo Finished Goods Zone', 'tan90_warehouse_id' => $warehouse->id,
            'zone_type' => 'Finished Goods Zone', 'status' => 'active', 'approval_status' => 'approved',
        ]);
        $rack = Rack::updateOrCreate(['code' => 'STG-O2FG-RACK'], [
            'name' => 'Stage Demo Rack', 'tan90_warehouse_zone_id' => $zone->id,
            'status' => 'active', 'approval_status' => 'approved',
        ]);
        $shelf = Shelf::updateOrCreate(['code' => 'STG-O2FG-SHELF'], [
            'name' => 'Stage Demo Shelf', 'tan90_rack_id' => $rack->id,
            'status' => 'active', 'approval_status' => 'approved',
        ]);

        return Bin::updateOrCreate(['code' => 'STG-O2FG-BIN'], [
            'name' => 'Stage Demo FG Bin', 'tan90_shelf_id' => $shelf->id,
            'status' => 'active', 'approval_status' => 'approved',
        ]);
    }

    private function invokeController(User $actor, string $controller, string $method, string $uri, array $data = [], array $arguments = []): mixed
    {
        Auth::setUser($actor);
        $request = Request::create($uri, 'POST', $data);
        $request->setUserResolver(fn () => $actor);

        return app($controller)->{$method}($request, ...$arguments);
    }
}
