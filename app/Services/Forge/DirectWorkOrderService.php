<?php

namespace App\Services\Forge;

use App\Models\Forge\ProductionFgMapping;
use App\Models\Forge\ProductionPlan;
use App\Models\Forge\WorkOrder;
use App\Models\Tan90\BomRecipeCosting\{Bom, Recipe, FinishedGood};
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\SiteLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DirectWorkOrderService
{
    public function definitions(int $fg): \Illuminate\Support\Collection
    {
        return collect([Recipe::class => 'recipe', Bom::class => 'bom'])->flatMap(fn ($type, $model) =>
            $model::where('tan90_finished_good_id', $fg)->where('status', 'active')->where('approval_status', 'approved')->get()
                ->map(fn ($r) => ['key' => $type.':'.$r->id, 'type' => $type, 'id' => $r->id, 'code' => $r->code]));
    }

    public function create(array $data, User $actor): WorkOrder
    {
        SiteLocation::assertCanAccessSiteRecord($actor, (int)$data['site_id']);
        return DB::transaction(function () use ($data, $actor) {
            // Serialize this creator's repeated submissions as well as the unique key.
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $existing = WorkOrder::where('creation_key', $data['creation_key'])->first();
            if ($existing) { abort_unless((int)$existing->created_by === (int)$actor->id, 403); return $existing; }
            $fg = FinishedGood::whereKey($data['finished_good_id'])->where('status','active')->where('approval_status','approved')->first();
            if (!$fg) throw ValidationException::withMessages(['finished_good_id' => 'Select an approved active finished good.']);
            $mapping = app(SalesProductionService::class)->approvedMapping((int)$data['site_id'], (int)$fg->id);
            $definitions = $this->definitions($fg->id);
            $definition = count($definitions) === 1 ? $definitions->first() : $definitions->firstWhere('key', $data['definition'] ?? '');
            if (!$definition) throw ValidationException::withMessages(['definition' => $definitions->isEmpty() ? 'Setup required: approve a recipe or BOM for this finished good.' : 'Select the approved recipe or BOM version for this order.']);
            if (!blank($data['production_plan_id'] ?? null)) {
                $plan = ProductionPlan::whereKey($data['production_plan_id'])->lockForUpdate()->firstOrFail();
                SiteLocation::assertCanAccessSiteRecord($actor, (int)$plan->site_id);
                if ($plan->status !== 'frozen' || (int)$plan->site_id !== (int)$data['site_id'] || (int)$plan->finished_good_id !== (int)$fg->id
                    || (float)$plan->workOrders()->sum('target_qty') + (float)$data['target_qty'] > (float)$plan->target_qty + 0.0005) {
                    throw ValidationException::withMessages(['production_plan_id' => 'Use an approved matching plan with enough unassigned quantity.']);
                }
            } else {
                $plan = ProductionPlan::create(['plan_number'=>'PP-WO-'.Str::upper(Str::ulid()), 'finished_good_id'=>$fg->id,
                    'site_id'=>$data['site_id'], 'target_qty'=>$data['target_qty'], 'uom'=>$fg->uom,
                    'due_date'=>$data['due_date'], 'status'=>'draft', 'created_by'=>$actor->id]);
            }
            $wo = WorkOrder::create(['wo_number'=>'WO-'.Str::upper(Str::ulid()), 'production_plan_id'=>$plan->id,
                'finished_good_id'=>$fg->id, $definition['type'].'_id'=>$definition['id'], 'target_qty'=>$data['target_qty'],
                'uom'=>$fg->uom, 'site_id'=>$data['site_id'], 'inventory_fg_item_id'=>$mapping->inventory_fg_item_id,
                'rm_warehouse_id'=>$mapping->rm_warehouse_id, 'fg_warehouse_id'=>$mapping->fg_warehouse_id,
                'reusable_pcm_item_id'=>$mapping->reusable_pcm_item_id, 'batch_number'=>$data['batch_number'] ?? null,
                'status'=>'draft', 'workflow_version'=>3, 'creation_key'=>$data['creation_key'], 'created_by'=>$actor->id]);
            AuditLogger::log('Direct work order created for review', $wo->wo_number, $wo);
            return $wo;
        });
    }
}
