<?php

namespace App\Services\Forge;

use App\Models\Forge\Batch;
use App\Models\Forge\MaterialIssue;
use App\Models\Forge\WorkOrder;
use App\Models\Inventory\InventoryItem;
use App\Models\Inventory\InventoryMovement;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BundleService
{
    /** Use the approved inventory BOM for consumption, but require a real physical pack-out. */
    public function confirmWithPackout(WorkOrder $wo, array $packout, User $actor): Batch
    {
        if ((int)$wo->workflow_version>=3) return $this->confirmWorkerPackout($wo,$packout,$actor);
        $wo->loadMissing('finalQcResult');
        $qc = $wo->finalQcResult;
        if (! $qc) {
            throw ValidationException::withMessages(['status' => 'Final QC result is required before automatic consumption and FG posting.']);
        }

        $bom = $wo->inventory_fg_item_id
            ? InventoryItem::findOrFail($wo->inventory_fg_item_id)->bomLines()->get()->keyBy('component_item_id')
            : collect();
        if ($bom->isEmpty()) {
            throw ValidationException::withMessages(['materials' => 'Map an approved FG recipe with RM/PM quantities before QC release.']);
        }
        $recordedOutput = (float) $wo->productionEntries()->where('status', 'approved')
            ->selectRaw('COALESCE(SUM(good_qty + rework_qty + rejected_qty), 0) as total')->value('total');
        $finalOutput = $recordedOutput > 0
            ? $recordedOutput
            : (float) $qc->accepted_qty + (float) $qc->rejected_qty + (float) $qc->rework_qty;
        $issues = $wo->materialIssues()->where('movement_type', 'issue')->whereNotNull('inventory_item_id')
            ->selectRaw('inventory_item_id, SUM(qty) as issued_qty')->groupBy('inventory_item_id')->get();
        if ($issues->isEmpty()) {
            throw ValidationException::withMessages(['materials' => 'Issue the work-order RM/PM before releasing final QC.']);
        }
        $issuedByItem = $issues->keyBy('inventory_item_id');
        foreach ($bom as $componentId => $line) {
            $required = round((float) $line->qty_per_unit * $finalOutput, 3);
            $issued = (float) ($issuedByItem->get($componentId)?->issued_qty ?? 0);
            if ($required > $issued + 0.0005) {
                $item = InventoryItem::find($componentId);
                throw ValidationException::withMessages([
                    'materials' => 'Issued '.($item?->safeDisplayName() ?? 'BOM material').' quantity is below BOM consumption for actual production. Issue the shortage before final QC release.',
                ]);
            }
        }

        $materials = $issues->map(function ($issue) use ($bom, $finalOutput) {
            $required = round((float) ($bom->get($issue->inventory_item_id)?->qty_per_unit ?? 0) * $finalOutput, 3);
            $issued = (float) $issue->issued_qty;
            if ($required > $issued + 0.0005) {
                $item = InventoryItem::find($issue->inventory_item_id);
                throw ValidationException::withMessages([
                    'materials' => 'Issued '.($item?->safeDisplayName() ?? 'material').' quantity is below BOM consumption for actual production. Issue the shortage before final QC release.',
                ]);
            }

            return [
                'inventory_item_id' => (int) $issue->inventory_item_id,
                'used_qty' => $required,
                'scrap_qty' => 0,
                'return_qty' => round($issued - $required, 3),
            ];
        })->values()->all();

        return $this->confirm($wo, [
            'bundle_count' => $packout['bundle_count'],
            'units_per_bundle' => $packout['units_per_bundle'],
            'auto_reconciled' => true,
            'loose_units' => $packout['loose_units'],
            'defective_qty' => $packout['defective_qty'],
            'defective_disposition' => $packout['defective_disposition'],
            'started_at' => $packout['started_at'],
            'ended_at' => $packout['ended_at'],
            'manpower' => $packout['manpower'],
            'materials' => $materials,
        ], $actor);
    }

    public function materialDefaults(WorkOrder $wo): \Illuminate\Support\Collection
    {
        $original=(float)$wo->productionEntries()->where('cycle_no',1)->where('status','approved')->selectRaw('COALESCE(SUM(good_qty + rework_qty + rejected_qty),0) as total')->value('total');
        $filled=(float)DB::table('forge_process_records')->where('work_order_id',$wo->id)->where('step','filling')->selectRaw('COALESCE(SUM(good_qty + rejected_qty),0) as total')->value('total');
        $bom=InventoryItem::findOrFail($wo->inventory_fg_item_id)->bomLines()->with('component')->get()->keyBy('component_item_id');
        return $wo->materialIssues()->where('movement_type','issue')->whereNotNull('inventory_item_id')->selectRaw('inventory_item_id,SUM(qty) as issued_qty')->groupBy('inventory_item_id')->get()->map(function($issue) use($bom,$original,$filled) {
            $line=$bom->get($issue->inventory_item_id); $item=$line?->component;
            $minimum=round((float)($line?->qty_per_unit??0)*($item?->item_type==='pm' ? $filled : $original),3);
            $used=$item?->item_type==='rm' ? (float)$issue->issued_qty : $minimum;
            return ['inventory_item_id'=>(int)$issue->inventory_item_id,'item'=>$item,'issued_qty'=>(float)$issue->issued_qty,
                'minimum_qty'=>$minimum,'used_qty'=>$used,'scrap_qty'=>0,'return_qty'=>max(0,round((float)$issue->issued_qty-$used,3))];
        });
    }

    private function confirmWorkerPackout(WorkOrder $wo,array $packout,User $actor): Batch
    {
        $defaults=$this->materialDefaults($wo)->keyBy('inventory_item_id');
        $lines=collect($packout['materials']??[])->keyBy('inventory_item_id');
        if($defaults->isEmpty() || $defaults->keys()->sort()->values()->all()!==$lines->keys()->map(fn($id)=>(int)$id)->sort()->values()->all()) {
            throw ValidationException::withMessages(['materials'=>'Review every issued material before confirming the bundle.']);
        }
        $materials=$defaults->map(function($base,$id) use($lines) {
            $line=$lines->get($id); $used=(float)$line['used_qty']; $scrap=(float)$line['scrap_qty'];
            if($used+$scrap<$base['minimum_qty']-0.0005 || $used+$scrap>$base['issued_qty']+0.0005) {
                throw ValidationException::withMessages(['materials'=>'Material consumption must cover actual production and stay within issued stock. Issue any extra packaging needed for rework.']);
            }
            if((abs($used-$base['used_qty'])>0.0005 || $scrap>0) && blank($line['reason']??null)) {
                throw ValidationException::withMessages(['materials'=>'Add a reason when changing suggested usage or recording scrap. Return only unused, unmixed material.']);
            }
            return ['inventory_item_id'=>$id,'used_qty'=>$used,'scrap_qty'=>$scrap,'return_qty'=>round($base['issued_qty']-$used-$scrap,3),'reason'=>$line['reason']??null];
        })->values()->all();
        return $this->confirm($wo,array_merge($packout,['auto_reconciled'=>true,'materials'=>$materials]),$actor);
    }

    public function confirm(WorkOrder $wo, array $data, User $actor): Batch
    {
        return DB::transaction(function () use ($wo, $data, $actor): Batch {
            $wo = WorkOrder::whereKey($wo->id)->lockForUpdate()->firstOrFail();
            if ((int) $wo->workflow_version < 2 || $wo->status !== 'bundle_pending' || $wo->batch()->exists()) {
                throw ValidationException::withMessages(['status' => 'Only a QC approved, unbundled new-flow work order can be confirmed once.']);
            }
            $qc = $wo->finalQcResult;
            if (! $qc || ! $qc->released_at || $qc->result !== 'released') {
                throw ValidationException::withMessages(['status' => 'Quality Manager approval is required before bundling.']);
            }
            $packQty = (int) $data['bundle_count'] * (float) $data['units_per_bundle'] + (float) $data['loose_units'];
            if (abs($packQty - (float) $qc->accepted_qty) > 0.0005) {
                throw ValidationException::withMessages(['loose_units' => 'Bundle units and loose units must equal QC accepted FG quantity.']);
            }
            if (abs((float) $data['defective_qty'] - ((float) $qc->rejected_qty + (float) $qc->rework_qty)) > 0.0005) {
                throw ValidationException::withMessages(['defective_qty' => 'Defective quantity must match QC rejected and rework quantity.']);
            }
            $issues = $wo->materialIssues()->where('movement_type', 'issue')->whereNotNull('inventory_item_id')
                ->selectRaw('inventory_item_id, SUM(qty) as issued_qty')->groupBy('inventory_item_id')->get()->keyBy('inventory_item_id');
            if ($issues->isEmpty() || count($data['materials']) !== $issues->count()) {
                throw ValidationException::withMessages(['materials' => 'Reconcile each issued RM/PM SKU.']);
            }
            $recordId = DB::table('forge_bundle_records')->insertGetId([
                'work_order_id' => $wo->id, 'final_qc_result_id' => $qc->id,
                'bundle_count' => $data['bundle_count'], 'units_per_bundle' => $data['units_per_bundle'],
                'auto_reconciled' => (bool) ($data['auto_reconciled'] ?? false),
                'loose_units' => $data['loose_units'], 'defective_qty' => $data['defective_qty'],
                'defective_disposition' => $data['defective_disposition'],
                'started_at' => $data['started_at'], 'ended_at' => $data['ended_at'],
                'manpower' => $data['manpower'], 'confirmed_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($data['materials'] as $line) {
                $itemId = (int) $line['inventory_item_id'];
                $issued = (float) ($issues->get($itemId)?->issued_qty ?? 0);
                $used = (float) $line['used_qty'];
                $scrap = (float) $line['scrap_qty'];
                $return = (float) $line['return_qty'];
                if ($issued <= 0 || abs($issued - $used - $scrap - $return) > 0.0005) {
                    throw ValidationException::withMessages(['materials' => 'Used + scrap + return must equal issued quantity for every item.']);
                }
                DB::table('forge_bundle_material_lines')->insert([
                    'bundle_record_id' => $recordId, 'inventory_item_id' => $itemId,
                    'used_qty' => $used, 'scrap_qty' => $scrap, 'return_qty' => $return,
                    'reason' => $line['reason'] ?? null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($return > 0) {
                    $item = InventoryItem::findOrFail($itemId);
                    $issue = MaterialIssue::create([
                        'work_order_id' => $wo->id, 'inventory_item_id' => $itemId,
                        'item_code' => $item->code ?: (string) $itemId,
                        'item_name' => $item->safeDisplayName(), 'qty' => $return,
                        'uom' => $item->unit, 'movement_type' => 'return',
                        'posted_by' => $actor->id, 'posted_at' => now(),
                    ]);
                    $move = InventoryMovement::create([
                        'item_id' => $itemId, 'type' => 'adjust_in', 'qty' => $return,
                        'location_id' => $wo->site_id, 'warehouse_id' => $wo->rm_warehouse_id,
                        'source_key' => 'forge-return:'.$issue->id, 'note' => $wo->wo_number,
                        'created_by' => $actor->id,
                    ]);
                    $issue->update(['inventory_movement_id' => $move->id]);
                }
            }
            $batch = Batch::create([
                'work_order_id' => $wo->id, 'batch_number' => $wo->batch_number ?: $wo->wo_number,
                'qty' => $qc->accepted_qty, 'uom' => $wo->uom,
                'status' => 'released', 'released_at' => now(),
            ]);
            $movementId = app(ProductionStockBridge::class)->receipt($wo, $batch->id, (float) $qc->accepted_qty, $actor);
            $batch->update(['inventory_movement_id' => $movementId]);
            $wo->update(['status' => 'released_to_fg']);
            app(ProductionTaskService::class)->done('wo:'.$wo->id.':bundle', $actor->id);
            app(ProductionTaskService::class)->open('batch:'.$batch->id.':receive', 'Receive FG batch '.$batch->batch_number,
                'flow.inventory.receive', (int) $wo->site_id, $actor->id, $batch);
            AuditLogger::log('Bundle confirmed and FG stock posted', $wo->wo_number, $batch);

            return $batch;
        });
    }
}
