<?php

namespace App\Services\Forge;

use App\Models\Forge\WorkOrder;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Support\SiteLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkerProduction
{
    public const STEPS = [
        'materials' => 'RM & PM Issue', 'mixing' => 'PCM Making',
        'filling-sealing' => 'Filling & Sealing', 'quality' => 'Quality Check',
        'consumption-fg' => 'Bundle', 'finished-goods' => 'FG Store',
    ];
    public const OPERATIONS = [
        'materials' => ['materials', 'forge.material.issue', 'Material handover'],
        'mixing' => ['mixing', 'forge.mixing.record', 'Mixing'],
        'filling' => ['filling-sealing', 'forge.filling.record', 'Filling'],
        'sealing' => ['filling-sealing', 'forge.sealing.record', 'Sealing'],
        'quality' => ['quality', 'forge.finalqc.record', 'Inspection'],
        'bundling' => ['consumption-fg', 'forge.bundle.confirm', 'Bundling'],
        'putaway' => ['finished-goods', 'flow.inventory.putaway', 'Putaway'],
    ];

    public static function permissionSources(): array
    {
        $sources = [];
        foreach (self::STEPS as $key => $label) {
            $sources['forge.step.'.$key.'.view'] = match ($key) {
                'quality' => 'forge.finalqc.record', 'finished-goods' => 'flow.inventory.view', default => 'forge.workorder.view',
            };
        }
        return $sources + ['forge.mixing.record' => 'forge.production.record', 'forge.filling.record' => 'forge.production.record',
            'forge.sealing.record' => 'forge.production.record', 'forge.bundle.confirm' => 'forge.production.approve',
            'forge.operation.correct' => 'forge.workorder.release'];
    }

    public static function permissionRows(): array
    {
        $rows = [];
        foreach (self::permissionSources() as $key => $source) {
            $view = str_ends_with($key, '.view');
            $rows[] = [$key, 'Forge', 'Production steps', $view ? 'page' : 'action', $view ? 'view' : 'record',
                str($key)->replace(['forge.', '.', '-'], ['', ' ', ' '])->headline()->toString(), null];
        }
        return $rows;
    }

    public function authorize(User $user, WorkOrder $wo, string $permission): void
    {
        abort_unless(app(AccessControlService::class)->can($user, $permission), 403);
        SiteLocation::assertCanAccessSiteRecord($user, $wo->site_id === null ? null : (int) $wo->site_id);
    }

    public function hasRecord(WorkOrder $wo, string $operation): bool
    {
        $cycle = (int) $wo->rework_generation + 1;
        if ($wo->operationRuns->contains(fn ($r) => $r->operation === $operation && (int) $r->cycle_no === $cycle && $r->status === 'completed')) return true;
        return match ($operation) {
            'mixing' => $cycle === 1 && $wo->mixingRecords->isNotEmpty(),
            'filling', 'sealing' => $wo->processRecords->contains(fn ($r) => $r->step === $operation && (int) $r->cycle_no === $cycle),
            'quality' => $wo->qcResults->contains(fn ($r) => (int) $r->cycle_no === $cycle),
            'bundling' => $wo->bundleRecords->isNotEmpty(),
            'putaway' => $wo->batch?->inventoryLot?->bin_id !== null,
            'materials' => $wo->materialIssues->where('movement_type', 'issue')->isNotEmpty(),
            default => false,
        };
    }

    public function load(WorkOrder $wo): WorkOrder
    {
        return $wo->loadMissing(['operationRuns.starter', 'mixingRecords', 'processRecords', 'qcResults', 'bundleRecords',
            'materialIssues', 'materialRequests', 'productionEntries', 'batch.inventoryLot', 'site', 'finishedGood','productionPlan.sourceOrderLine.order']);
    }

    public function pending(WorkOrder $wo, string $step): bool
    {
        if (in_array($wo->status, ['closed', 'rejected', 'draft'], true)) return false;
        return match ($step) {
            'materials' => $wo->status === 'released' || $wo->materialRequests->contains('status', 'requested'),
            'mixing' => in_array($wo->status, ['material_issued', 'in_progress', 'rework'], true) && !$this->hasRecord($wo, 'mixing'),
            'filling-sealing' => in_array($wo->status, ['in_progress', 'reconciliation'], true) && $this->hasRecord($wo, 'mixing')
                && (!$this->hasRecord($wo, 'sealing') || $wo->productionEntries->contains(fn ($r) => (int)$r->cycle_no === (int)$wo->rework_generation + 1 && $r->status === 'draft')),
            'quality' => $wo->status === 'final_qc_pending',
            'consumption-fg' => $wo->status === 'bundle_pending',
            'finished-goods' => $wo->status === 'released_to_fg' && !$this->hasRecord($wo,'putaway'),
            default => false,
        };
    }

    public function operation(WorkOrder $wo, string $step): string
    {
        return match ($step) {
            'filling-sealing' => $this->hasRecord($wo, 'filling') ? 'sealing' : 'filling',
            'consumption-fg' => 'bundling', 'finished-goods' => 'putaway', default => $step,
        };
    }

    public function queue(string $step, bool $history = false): \Illuminate\Database\Eloquent\Builder
    {
        $q=WorkOrder::query()->where('workflow_version','>=',2);
        $cycle=fn($r)=>$r->whereColumn('cycle_no',DB::raw('forge_work_orders.rework_generation + 1'));
        if ($history) {
            return match($step) {
                'materials'=>$q->whereHas('materialIssues',fn($r)=>$r->where('movement_type','issue')),
                'mixing'=>$q->where(fn($r)=>$r->whereHas('mixingRecords')->orWhereHas('operationRuns',fn($s)=>$s->where('operation','mixing')->where('status','completed'))),
                'filling-sealing'=>$q->whereHas('processRecords',fn($r)=>$r->where('step','sealing')),
                'quality'=>$q->whereHas('qcResults',fn($r)=>$r->whereNotNull('released_at')),
                'consumption-fg'=>$q->has('bundleRecords'),
                'finished-goods'=>$q->whereHas('batch.inventoryLot',fn($r)=>$r->whereNotNull('bin_id')),
            };
        }
        $q->whereNotIn('status',['draft','closed','rejected']);
        return match($step) {
            'materials'=>$q->where(fn($r)=>$r->where('status','released')->orWhereHas('materialRequests',fn($s)=>$s->where('status','requested'))),
            'mixing'=>$q->whereIn('status',['material_issued','in_progress','rework'])
                ->whereDoesntHave('operationRuns',fn($r)=>$cycle($r)->where('operation','mixing')->where('status','completed'))
                ->where(fn($r)=>$r->where('rework_generation','>',0)->orWhereDoesntHave('mixingRecords')),
            'filling-sealing'=>$q->whereIn('status',['in_progress','reconciliation'])
                ->where(fn($r)=>$r->whereHas('operationRuns',fn($s)=>$cycle($s)->where('operation','mixing')->where('status','completed'))
                    ->orWhere(fn($s)=>$s->where('rework_generation',0)->has('mixingRecords')))
                ->where(fn($r)=>$r->whereDoesntHave('processRecords',fn($s)=>$cycle($s)->where('step','sealing'))
                    ->orWhereHas('productionEntries',fn($s)=>$cycle($s)->where('status','draft'))),
            'quality'=>$q->where('status','final_qc_pending'),
            'consumption-fg'=>$q->where('status','bundle_pending'),
            'finished-goods'=>$q->where('status','released_to_fg')->whereDoesntHave('batch.inventoryLot',fn($r)=>$r->whereNotNull('bin_id')),
        };
    }

    public function assertStartable(WorkOrder $wo, string $operation, int $context): void
    {
        if ($wo->hasOpenHold()) throw ValidationException::withMessages(['status' => 'Resolve the quality hold before starting.']);
        $this->load($wo);
        $valid = match ($operation) {
            'materials' => $wo->materialRequests->contains(fn ($r) => (int)$r->id === $context && $r->status === 'requested') && !in_array($wo->status, ['draft','closed','rejected']),
            'mixing' => $this->pending($wo, 'mixing'),
            'filling' => $this->pending($wo, 'filling-sealing') && !$this->hasRecord($wo, 'filling'),
            'sealing' => $this->pending($wo, 'filling-sealing') && $this->hasRecord($wo, 'filling') && !$this->hasRecord($wo, 'sealing'),
            'quality' => $wo->status === 'final_qc_pending' && !$this->hasRecord($wo, 'quality'),
            'bundling' => $wo->status === 'bundle_pending' && !$wo->batch,
            'putaway' => $wo->status === 'released_to_fg' && $wo->batch?->inventoryLot?->status === 'staged'
                && \App\Models\Tan90\MasterData\Bin::where('status','active')->where('approval_status','approved')
                    ->whereHas('shelf.rack.zone',fn($q)=>$q->where('tan90_warehouse_id',$wo->fg_warehouse_id))->exists(),
            default => false,
        };
        if (!$valid) throw ValidationException::withMessages(['status' => 'This operation is not ready. Complete the preceding step or review its saved record.']);
    }
}
