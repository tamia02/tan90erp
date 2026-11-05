<?php

namespace App\Services\Forge;

use App\Models\Forge\Batch;
use App\Models\Forge\FinalQcResult;
use App\Models\Forge\JobCard;
use App\Models\Forge\Machine;
use App\Models\Forge\MaterialIssue;
use App\Models\Forge\ProductionEntry;
use App\Models\Forge\WorkOrder;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Owns every Work Order state transition. Transitions are commands, not
// editable status fields (ARCHITECTURE.md #5) - every method here checks
// the current state before moving, so a status can never be hand-edited
// into an invalid position by a form. Only Blueprint's released BOM/Recipe/
// Routing can be snapshotted onto a work order; Forge never writes back to
// those tables.
class WorkOrderService
{
    public function release(WorkOrder $wo, User $actor): WorkOrder
    {
        $this->assertStatus($wo, ['draft']);

        if ($wo->bom_id === null && $wo->recipe_id === null) {
            throw ValidationException::withMessages(['status' => 'Work order needs a released BOM or Recipe before it can be released.']);
        }
        if ($wo->inventory_fg_item_id) {
            app(ProductionStockBridge::class)->validateMapping($wo);
            $definition = $wo->recipe ?: $wo->bom;
            if (! $definition || $definition->status !== 'active' || $definition->approval_status !== 'approved'
                || (int) $definition->tan90_finished_good_id !== (int) $wo->finished_good_id) {
                throw ValidationException::withMessages(['recipe_id' => 'Select an approved active BOM or recipe for this finished good.']);
            }
        }

        $wo->update(['status' => 'released', 'released_by' => $actor->id, 'released_at' => now()]);
        if ((int) $wo->workflow_version >= 2) {
            app(ProductionTaskService::class)->done('wo:'.$wo->id.':release', $actor->id);
            app(ProductionTaskService::class)->open('wo:'.$wo->id.':request', 'Request RM/PM for '.$wo->wo_number,
                'forge.production.record', (int) $wo->site_id, $actor->id, $wo);
        }
        AuditLogger::log('Work order released', $wo->wo_number, $wo);

        return $wo;
    }

    public function reserveMaterial(WorkOrder $wo): WorkOrder
    {
        if ((int) $wo->workflow_version >= 2) {
            throw ValidationException::withMessages(['status' => 'Operator must send a partial or full Request Items form for this work order.']);
        }
        $this->assertStatus($wo, ['released']);
        DB::transaction(function () use ($wo): void {
            if ($wo->inventory_fg_item_id) {
                app(ProductionStockBridge::class)->reserve($wo);
            }
            $wo->update(['status' => 'material_reserved']);
        });
        AuditLogger::log('Work order material reserved', $wo->wo_number, $wo);

        return $wo;
    }

    public function issueMaterial(WorkOrder $wo, array $lines, User $actor, array $handover = []): WorkOrder
    {
        if ((int) $wo->workflow_version >= 2) {
            throw ValidationException::withMessages(['status' => 'Issue an approved operator material request instead.']);
        }
        $this->assertStatus($wo, ['material_reserved']);

        DB::transaction(function () use ($wo, $lines, $actor, $handover) {
            if ($wo->inventory_fg_item_id) {
                app(ProductionStockBridge::class)->issue($wo, $actor);
            } else {
                foreach ($lines as $line) {
                MaterialIssue::create([
                    'work_order_id' => $wo->id,
                    'item_code' => $line['item_code'],
                    'item_name' => $line['item_name'],
                    'lot_number' => $line['lot_number'] ?? null,
                    'qty' => $line['qty'],
                    'uom' => $line['uom'],
                    'movement_type' => 'issue',
                    'posted_by' => $actor->id,
                    'posted_at' => now(),
                ]);
                }
            }
            $wo->update([
                'status' => 'material_issued',
                'material_handover_started_at' => $handover['handover_started_at'] ?? null,
                'material_handover_ended_at' => $handover['handover_ended_at'] ?? null,
                'material_handover_manpower' => $handover['manpower'] ?? null,
                'material_handover_by' => $handover ? $actor->id : null,
            ]);
        });

        AuditLogger::log('Material issued to work order', $wo->wo_number, $wo);

        return $wo;
    }

    public function startProgress(WorkOrder $wo): WorkOrder
    {
        $this->assertStatus($wo, ['material_issued']);
        $wo->update(['status' => 'in_progress']);
        if ((int) $wo->workflow_version >= 2) {
            app(ProductionTaskService::class)->open('wo:'.$wo->id.':mixing', 'Record manual PCM mixing for '.$wo->wo_number,
                'forge.production.record', (int) $wo->site_id, (int) $wo->created_by, $wo);
        }
        AuditLogger::log('Work order started', $wo->wo_number, $wo);

        return $wo;
    }

    public function resumeRework(WorkOrder $wo, User $actor): WorkOrder
    {
        return DB::transaction(function () use ($wo, $actor): WorkOrder {
            $wo = WorkOrder::whereKey($wo->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($wo, ['rework']);
            if ($wo->hasOpenHold()) {
                throw ValidationException::withMessages(['status' => 'Resolve the quality hold before resuming this work order.']);
            }
            $wo->update(['status' => 'in_progress']);
            if ((int) $wo->workflow_version >= 2) {
                app(ProductionTaskService::class)->open('wo:'.$wo->id.':rework:'.$wo->rework_generation,
                    'Continue same-work-order rework for '.$wo->wo_number,
                    'forge.production.record', (int) $wo->site_id, $actor->id, $wo);
            }
            AuditLogger::log('Same work order resumed for rework', $wo->wo_number, $wo);

            return $wo;
        });
    }

    public function recordProduction(WorkOrder $wo, array $data, User $actor): ProductionEntry
    {
        $this->assertStatus($wo, ['in_progress', 'reconciliation']);

        if ($wo->hasOpenHold()) {
            throw ValidationException::withMessages(['status' => 'Work order has an open quality hold - resolve it before recording production.']);
        }
        if ($wo->inventory_fg_item_id) {
            $cycleNo = (int) $wo->rework_generation + 1;
            $sealed = (float) DB::table('forge_process_records')->where('work_order_id', $wo->id)->where('cycle_no', $cycleNo)->where('step', 'sealing')->sum('good_qty');
            $recorded = (float) $wo->productionEntries()->where('cycle_no', $cycleNo)->sum('good_qty');
            if ($sealed <= 0 || $recorded + (float) $data['good_qty'] > $sealed + 0.0005) {
                throw ValidationException::withMessages(['good_qty' => 'Good quantity must be covered by recorded sealing output.']);
            }
        }

        $entry = ProductionEntry::create([
            'work_order_id' => $wo->id,
            'job_card_id' => $data['job_card_id'] ?? null,
            'good_qty' => $data['good_qty'],
            'rework_qty' => $data['rework_qty'] ?? 0,
            'rejected_qty' => $data['rejected_qty'] ?? 0,
            'uom' => $wo->uom,
            'cycle_no' => (int) $wo->rework_generation + 1,
            'status' => 'draft',
            'recorded_by' => $actor->id,
        ]);

        if ($wo->status === 'in_progress') {
            $wo->update(['status' => 'reconciliation']);
        }

        AuditLogger::log('Production entry recorded', $wo->wo_number, $entry);

        return $entry;
    }

    public function approveProduction(ProductionEntry $entry, User $actor): ProductionEntry
    {
        if ($entry->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only draft entries can be approved.']);
        }

        DB::transaction(function () use ($entry, $actor) {
            $entry->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

            $wo = $entry->workOrder;
            $wo->increment('good_qty', $entry->good_qty);
            $wo->increment('rework_qty', $entry->rework_qty);
            $wo->increment('rejected_qty', $entry->rejected_qty);
        });

        AuditLogger::log('Production entry approved', $entry->workOrder->wo_number, $entry);

        return $entry;
    }

    public function sendToFinalQc(WorkOrder $wo): WorkOrder
    {
        $this->assertStatus($wo, ['reconciliation']);

        if ((float) $wo->good_qty <= 0) {
            throw ValidationException::withMessages(['status' => 'No reconciled good quantity to send for final QC.']);
        }
        $cycleNo = (int) $wo->rework_generation + 1;
        if ($wo->inventory_fg_item_id && (! DB::table('forge_mixing_records')->where('work_order_id', $wo->id)->exists()
            || ! DB::table('forge_process_records')->where('work_order_id', $wo->id)->where('cycle_no', $cycleNo)->where('step', 'filling')->exists()
            || ! DB::table('forge_process_records')->where('work_order_id', $wo->id)->where('cycle_no', $cycleNo)->where('step', 'sealing')->exists())) {
            throw ValidationException::withMessages(['status' => 'Record manual mixing, filling and sealing before final QC.']);
        }

        $wo->update(['status' => 'final_qc_pending']);
        if ((int) $wo->workflow_version >= 2) {
            $cycleNo = (int) $wo->rework_generation + 1;
            app(ProductionTaskService::class)->done('wo:'.$wo->id.':production');
            app(ProductionTaskService::class)->done('wo:'.$wo->id.':production:cycle:'.$cycleNo);
            app(ProductionTaskService::class)->done('wo:'.$wo->id.':qc');
            app(ProductionTaskService::class)->open('wo:'.$wo->id.':qc:cycle:'.$cycleNo, 'Record leak and pressure QC for '.$wo->wo_number,
                'forge.finalqc.record', (int) $wo->site_id, (int) $wo->created_by, $wo);
        }
        AuditLogger::log('Work order sent to final QC', $wo->wo_number, $wo);

        return $wo;
    }

    public function recordFinalQc(WorkOrder $wo, array $data, User $actor): FinalQcResult
    {
        $this->assertStatus($wo, ['final_qc_pending']);
        if((int)$wo->workflow_version>=3) {
            if($wo->qcResults()->where('cycle_no',(int)$wo->rework_generation+1)->exists()) throw ValidationException::withMessages(['status'=>'This QC round is already recorded. Review and approve its decision.']);
            if($data['result']==='rework' && ((float)($data['rework_qty']??0)<=0 || (float)($data['recovered_pcm_qty']??0)<=0)) throw ValidationException::withMessages(['rework_qty'=>'Rework needs failed units and recovered PCM for the same-order recovery loop.']);
            if($data['result']==='released' && (float)($data['rework_qty']??0)>0) throw ValidationException::withMessages(['result'=>'Choose Rework while any units still require reprocessing.']);
            if($data['result']==='rejected' && (float)$data['accepted_qty']>0) throw ValidationException::withMessages(['accepted_qty'=>'A rejected run cannot contain accepted units. Release accepted units or choose rework.']);
        }
        if ($wo->inventory_fg_item_id) {
            $total = (float) $data['accepted_qty'] + (float) ($data['rejected_qty'] ?? 0) + (float) ($data['rework_qty'] ?? 0);
            if ($total > (float) $wo->good_qty + 0.0005) {
                throw ValidationException::withMessages(['accepted_qty' => 'QC quantities exceed the approved production output.']);
            }
            if ($data['result'] === 'released' && (($data['leak_test'] ?? '') !== 'pass' || ($data['pressure_test'] ?? '') !== 'pass'
                || ((int) $wo->workflow_version >= 2 && ($data['physical_check'] ?? '') !== 'pass'))) {
                throw ValidationException::withMessages(['result' => 'Leak, pressure and physical checks must pass before FG release.']);
            }
            if ((int) $wo->workflow_version >= 2 && abs($total - (float) $wo->good_qty) > 0.0005) {
                throw ValidationException::withMessages(['accepted_qty' => 'QC must account for all approved production output as accepted, rejected or rework.']);
            }
            if ((int) $wo->workflow_version >= 2 && (float) ($data['recovered_pcm_qty'] ?? 0) > 0) {
                $continuousFeed = (float) DB::table('forge_process_records')
                    ->where('work_order_id', $wo->id)->whereNotNull('continuous_feed_qty')->sum('continuous_feed_qty');
                $previouslyRecovered = (float) DB::table('forge_final_qc_results')
                    ->where('work_order_id', $wo->id)->sum('recovered_pcm_qty');
                if ($previouslyRecovered + (float) $data['recovered_pcm_qty'] > $continuousFeed + 0.0005) {
                    throw ValidationException::withMessages(['recovered_pcm_qty' => 'Recovered PCM across QC rounds cannot exceed the single continuous feed recorded for this work order.']);
                }
            }
        }

        $result = FinalQcResult::create([
            'work_order_id' => $wo->id,
            'cycle_no' => (int)$wo->rework_generation + 1,
            'accepted_qty' => $data['accepted_qty'],
            'rejected_qty' => $data['rejected_qty'] ?? 0,
            'rework_qty' => $data['rework_qty'] ?? 0,
            'specification_results' => $data['specification_results'] ?? null,
            'result' => $data['result'],
            'leak_test' => $data['leak_test'] ?? null,
            'pressure_test' => $data['pressure_test'] ?? null,
            'physical_check' => $data['physical_check'] ?? null,
            'recovered_pcm_qty' => $data['recovered_pcm_qty'] ?? 0,
            'cycle_accepted_qty' => $data['accepted_qty'],
            'cycle_rejected_qty' => $data['rejected_qty'] ?? 0,
            'cycle_rework_qty' => $data['rework_qty'] ?? 0,
            'started_at' => $data['started_at'] ?? null,
            'ended_at' => $data['ended_at'] ?? null,
            'manpower' => $data['manpower'] ?? null,
            'inspected_by' => $actor->id,
        ]);

        AuditLogger::log('Final QC recorded', $wo->wo_number, $result);
        if ((int) $wo->workflow_version >= 2) {
            $cycleNo = (int) $wo->rework_generation + 1;
            app(ProductionTaskService::class)->done('wo:'.$wo->id.':qc', $actor->id);
            app(ProductionTaskService::class)->done('wo:'.$wo->id.':qc:cycle:'.$cycleNo, $actor->id);
            app(ProductionTaskService::class)->open('wo:'.$wo->id.':qc-release:cycle:'.$cycleNo, 'Approve final QC for '.$wo->wo_number,
                'forge.finalqc.release', (int) $wo->site_id, $actor->id, $wo);
        }

        return $result;
    }

    public function releaseFinalQc(FinalQcResult $result, User $actor): WorkOrder
    {
        $wo = $result->workOrder;
        $this->assertStatus($wo, ['final_qc_pending']);

        $statusMap = ['released' => (int) $wo->workflow_version >= 2 ? 'bundle_pending' : 'released_to_fg', 'rework' => 'rework', 'rejected' => 'rejected'];
        $newStatus = $statusMap[$result->result] ?? 'rejected';

        DB::transaction(function () use ($wo, $result, $actor, $newStatus) {
            $result->update(['released_by' => $actor->id, 'released_at' => now()]);
            if ($newStatus === 'rework') {
                $wo->update([
                    'status' => 'rework',
                    'qc_accepted_carry_qty' => (float) $wo->qc_accepted_carry_qty + (float) ($result->cycle_accepted_qty ?? $result->accepted_qty),
                    'qc_rejected_carry_qty' => (float) $wo->qc_rejected_carry_qty + (float) ($result->cycle_rejected_qty ?? $result->rejected_qty),
                    'rework_generation' => (int) $wo->rework_generation + 1,
                    'good_qty' => 0, 'rework_qty' => 0, 'rejected_qty' => 0,
                ]);
            } else {
                if ((int) $wo->workflow_version >= 2 && $newStatus === 'bundle_pending') {
                    $result->update([
                        'accepted_qty' => (float) ($result->cycle_accepted_qty ?? $result->accepted_qty) + (float) $wo->qc_accepted_carry_qty,
                        'rejected_qty' => (float) ($result->cycle_rejected_qty ?? $result->rejected_qty) + (float) $wo->qc_rejected_carry_qty,
                    ]);
                }
                $wo->update(['status' => $newStatus]);
                // A released QC result now waits at the physical bundle-count page.
                // Consumption and FG posting happen together when pack-out is confirmed.
            }

            if ($newStatus === 'released_to_fg') {
                $batch = Batch::create([
                    'work_order_id' => $wo->id,
                    'batch_number' => $wo->batch_number ?: $wo->wo_number,
                    'qty' => $result->accepted_qty,
                    'uom' => $wo->uom,
                    'status' => 'released',
                    'released_at' => now(),
                ]);
                if ($wo->inventory_fg_item_id) {
                    $movementId = app(ProductionStockBridge::class)->receipt($wo, $batch->id, (float) $result->accepted_qty, $actor);
                    $batch->update(['inventory_movement_id' => $movementId]);
                }
            }
        });

        $wo->refresh();
        AuditLogger::log('Final QC released - '.$wo->status, $wo->wo_number, $wo);
        if ((int) $wo->workflow_version >= 2) {
            app(ProductionTaskService::class)->done('wo:'.$wo->id.':qc-release', $actor->id);
            app(ProductionTaskService::class)->done('wo:'.$wo->id.':qc-release:cycle:'.((int) $wo->rework_generation + 1), $actor->id);
            if ($newStatus === 'bundle_pending') {
                app(ProductionTaskService::class)->open('wo:'.$wo->id.':bundle', 'Count physical bundles for '.$wo->wo_number,
                    'forge.production.approve', (int) $wo->site_id, $actor->id, $wo);
            } elseif ($newStatus === 'rework') {
                app(ProductionTaskService::class)->open('wo:'.$wo->id.':rework:'.$wo->rework_generation,
                    'Resume same-work-order rework for '.$wo->wo_number,
                    'forge.production.record', (int) $wo->site_id, $actor->id, $wo);
            }
        }

        return $wo->refresh();
    }

    public function close(WorkOrder $wo): WorkOrder
    {
        if($wo->status==='closed') return $wo;
        $this->assertStatus($wo, ['released_to_fg', 'rejected']);
        if((int)$wo->workflow_version>=3 && $wo->status==='released_to_fg' && (!$wo->batch?->inventoryLot?->bin_id || $wo->batch?->inventoryLot?->status!=='available')) {
            throw ValidationException::withMessages(['status'=>'Receive and put away the FG batch before closing this work order.']);
        }
        $wo->update(['status' => 'closed', 'closed_at' => now()]);
        AuditLogger::log('Work order closed', $wo->wo_number, $wo);

        return $wo;
    }

    public function startJobCard(JobCard $card, ?int $machineId = null, ?User $actor = null, bool $machineSelectionProvided = false): JobCard
    {
        if ($card->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Only a pending job card can be started.']);
        }

        if ($card->precedingCardsIncomplete()) {
            throw ValidationException::withMessages(['status' => 'A preceding operation is not yet completed.']);
        }

        if ($card->workOrder->hasOpenHold()) {
            throw ValidationException::withMessages(['status' => 'Work order has an open quality hold.']);
        }

        $machineId = $machineSelectionProvided ? $machineId : ($machineId ?? $card->machine_id);
        if ($machineId !== null) {
            $machine = Machine::findOrFail($machineId);
            if ($machine->status !== 'active' || in_array($machine->state, ['down', 'maintenance'], true) || $machine->openDowntimeEvent()) {
                throw ValidationException::withMessages(['machine_id' => 'Choose an active machine that is not stopped.']);
            }
        }

        DB::transaction(function () use ($card, $machineId, $actor) {
            $startedAt = now();
            $card->update([
                'machine_id' => $machineId,
                'operator_user_id' => $card->operator_user_id ?? $actor?->id,
                'status' => 'started',
                'started_at' => $startedAt,
            ]);
            if ($machineId !== null) {
                DB::table('forge_machine_run_intervals')->insert([
                    'machine_id' => $machineId, 'job_card_id' => $card->id,
                    'started_at' => $startedAt, 'created_at' => $startedAt, 'updated_at' => $startedAt,
                ]);
                Machine::whereKey($machineId)->update(['state' => 'running']);
            }
        });
        AuditLogger::log('Job card started', $card->operation_name, $card);

        return $card;
    }

    public function pauseJobCard(JobCard $card): JobCard
    {
        $this->assertJobCardStatus($card, ['started']);
        DB::transaction(function () use ($card) {
            $pausedAt = now();
            $card->update(['status' => 'paused', 'paused_at' => $pausedAt]);
            DB::table('forge_machine_run_intervals')->where('job_card_id', $card->id)
                ->whereNull('ended_at')->update(['ended_at' => $pausedAt, 'updated_at' => $pausedAt]);
            $this->setMachineIdleWhenNoJobRuns($card);
        });
        AuditLogger::log('Job card paused', $card->operation_name, $card);

        return $card;
    }

    public function resumeJobCard(JobCard $card): JobCard
    {
        $this->assertJobCardStatus($card, ['paused']);
        if ($card->machine_id !== null) {
            $machine = $card->machine;
            if ($machine->status !== 'active' || in_array($machine->state, ['down', 'maintenance'], true) || $machine->openDowntimeEvent()) {
                throw ValidationException::withMessages(['machine_id' => 'Machine is stopped; close its downtime before resuming.']);
            }
        }

        DB::transaction(function () use ($card) {
            $resumedAt = now();
            $card->update(['status' => 'started', 'paused_at' => null]);
            if ($card->machine_id !== null) {
                DB::table('forge_machine_run_intervals')->insert([
                    'machine_id' => $card->machine_id, 'job_card_id' => $card->id,
                    'started_at' => $resumedAt, 'created_at' => $resumedAt, 'updated_at' => $resumedAt,
                ]);
                Machine::whereKey($card->machine_id)->update(['state' => 'running']);
            }
        });
        AuditLogger::log('Job card resumed', $card->operation_name, $card);

        return $card;
    }

    public function completeJobCard(JobCard $card): JobCard
    {
        $this->assertJobCardStatus($card, ['started', 'paused']);
        DB::transaction(function () use ($card) {
            $completedAt = now();
            $card->update(['status' => 'completed', 'completed_at' => $completedAt]);
            DB::table('forge_machine_run_intervals')->where('job_card_id', $card->id)
                ->whereNull('ended_at')->update(['ended_at' => $completedAt, 'updated_at' => $completedAt]);
            $this->setMachineIdleWhenNoJobRuns($card);
        });
        AuditLogger::log('Job card completed', $card->operation_name, $card);

        return $card;
    }

    private function assertStatus(WorkOrder $wo, array $allowed): void
    {
        if (! in_array($wo->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "Work order {$wo->wo_number} is '{$wo->status}' - this action needs one of: ".implode(', ', $allowed).'.',
            ]);
        }
    }

    private function setMachineIdleWhenNoJobRuns(JobCard $card): void
    {
        if ($card->machine_id === null || DB::table('forge_machine_run_intervals')
            ->where('machine_id', $card->machine_id)->whereNull('ended_at')->exists()) {
            return;
        }

        Machine::whereKey($card->machine_id)->where('state', 'running')->update(['state' => 'idle']);
    }

    private function assertJobCardStatus(JobCard $card, array $allowed): void
    {
        if (! in_array($card->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "Job card is '{$card->status}' - this action needs one of: ".implode(', ', $allowed).'.',
            ]);
        }
    }
}
