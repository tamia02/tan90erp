<?php

namespace App\Services\Forge;

use App\Models\Forge\WorkOrder;
use App\Models\Inventory\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialRequestService
{
    public function request(WorkOrder $wo, array $lines, User $actor): int
    {
        return DB::transaction(function () use ($wo, $lines, $actor): int {
            $wo = WorkOrder::whereKey($wo->id)->lockForUpdate()->firstOrFail();
            if ((int) $wo->workflow_version < 2 || ! in_array($wo->status, ['released', 'material_issued', 'in_progress', 'reconciliation', 'final_qc_pending', 'bundle_pending', 'rework'], true)) {
                throw ValidationException::withMessages(['status' => 'This work order cannot request material now.']);
            }
            $bom = InventoryItem::findOrFail($wo->inventory_fg_item_id)->bomLines()->get()->keyBy('component_item_id');
            $id = DB::table('forge_material_requests')->insertGetId([
                'work_order_id' => $wo->id, 'status' => 'requested', 'requested_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lines as $line) {
                $itemId = (int) $line['inventory_item_id'];
                $recipeLine = $bom->get($itemId);
                if (! $recipeLine || (float) $line['qty'] <= 0) {
                    throw ValidationException::withMessages(['materials' => 'Request only positive quantities from the approved Inventory recipe.']);
                }
                $previous = (float) DB::table('forge_material_issues')->where('work_order_id', $wo->id)
                    ->where('inventory_item_id', $itemId)->where('movement_type', 'issue')->sum('qty');
                $open = (float) DB::table('forge_material_request_lines as l')->join('forge_material_requests as r', 'r.id', '=', 'l.material_request_id')
                    ->where('r.work_order_id', $wo->id)->where('r.status', 'requested')->where('l.inventory_item_id', $itemId)->sum('l.qty');
                $standard = (float) $recipeLine->qty_per_unit * (float) $wo->target_qty;
                if ($previous + $open + (float) $line['qty'] > $standard + 0.0005 && blank($line['extra_reason'] ?? null)) {
                    throw ValidationException::withMessages(['materials' => 'Quantity above approved BOM needs an extra material reason.']);
                }
                DB::table('forge_material_request_lines')->insert([
                    'material_request_id' => $id, 'inventory_item_id' => $itemId,
                    'qty' => $line['qty'], 'extra_reason' => $line['extra_reason'] ?? null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            app(ProductionTaskService::class)->done('wo:'.$wo->id.':request', $actor->id);
            app(ProductionTaskService::class)->open('request:'.$id.':issue', 'Issue requested RM/PM for '.$wo->wo_number,
                'forge.material.issue', (int) $wo->site_id, $actor->id, $wo);

            return $id;
        });
    }

    public function approveAndIssue(WorkOrder $wo, int $requestId, array $handover, User $actor): void
    {
        DB::transaction(function () use ($wo, $requestId, $handover, $actor): void {
            $wo = WorkOrder::whereKey($wo->id)->lockForUpdate()->firstOrFail();
            $request = DB::table('forge_material_requests')->where('id', $requestId)->where('work_order_id', $wo->id)->lockForUpdate()->first();
            if (! $request || $request->status !== 'requested' || (int) $wo->workflow_version < 2) {
                throw ValidationException::withMessages(['materials' => 'Request was already issued or belongs to another work order.']);
            }
            if (strtotime($handover['handover_ended_at']) < strtotime($handover['handover_started_at'])) {
                throw ValidationException::withMessages(['handover_ended_at' => 'Handover end must be after start.']);
            }
            app(ProductionStockBridge::class)->issueRequested($wo, $requestId, $actor);
            DB::table('forge_material_requests')->where('id', $requestId)->update([
                'status' => 'issued', 'approved_by' => $actor->id, 'approved_at' => now(),
                'handover_started_at' => $handover['handover_started_at'], 'handover_ended_at' => $handover['handover_ended_at'],
                'manpower' => $handover['manpower'], 'updated_at' => now(),
            ]);
            $update = ['material_handover_started_at' => $handover['handover_started_at'],
                'material_handover_ended_at' => $handover['handover_ended_at'], 'material_handover_manpower' => $handover['manpower'],
                'material_handover_by' => $actor->id];
            if ($wo->status === 'released') {
                $update['status'] = 'material_issued';
            }
            $wo->update($update);
            app(ProductionTaskService::class)->done('request:'.$requestId.':issue', $actor->id);
        });
    }

    public function maxSupportedOutput(WorkOrder $wo): float
    {
        $bom = InventoryItem::findOrFail($wo->inventory_fg_item_id)->bomLines()->get();
        if ($bom->isEmpty()) {
            return 0;
        }
        $coverage = [];
        foreach ($bom as $line) {
            $issued = (float) DB::table('forge_material_issues')->where('work_order_id', $wo->id)
                ->where('inventory_item_id', $line->component_item_id)->where('movement_type', 'issue')->sum('qty');
            $returned = (float) DB::table('forge_material_issues')->where('work_order_id', $wo->id)
                ->where('inventory_item_id', $line->component_item_id)->where('movement_type', 'return')->sum('qty');
            $coverage[] = ($issued - $returned) / (float) $line->qty_per_unit;
        }

        return max(0, min($coverage));
    }
}
