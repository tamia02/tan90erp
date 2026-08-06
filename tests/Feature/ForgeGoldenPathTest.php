<?php

namespace Tests\Feature;

use App\Models\Forge\WorkOrder;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Models\User;
use Tests\TestCase;

class ForgeGoldenPathTest extends TestCase
{
    public function test_all_forge_index_pages_load_for_manufacturing_head(): void
    {
        $head = User::where('email', 'head.manufacturing@tan90.demo')->firstOrFail();

        foreach ([
            'forge.dashboard', 'forge.plans.index', 'forge.workorders.index',
            'forge.machines.index', 'forge.wastage.index', 'forge.quality-holds.index',
            'forge.final-qc.index', 'forge.deviations.index', 'forge.batches.index',
        ] as $route) {
            $this->actingAs($head)->get(route($route))->assertOk();
        }
    }

    public function test_planner_cannot_release_work_order(): void
    {
        $planner = User::where('email', 'planner@tan90.demo')->firstOrFail();
        $wo = WorkOrder::first();

        $this->actingAs($planner)->post(route('forge.workorders.release', $wo))->assertForbidden();
    }

    public function test_full_work_order_lifecycle_to_released_batch(): void
    {
        $planner = User::where('email', 'planner@tan90.demo')->firstOrFail();
        $supervisor = User::where('email', 'supervisor@tan90.demo')->firstOrFail();
        $operator = User::where('email', 'operator@tan90.demo')->firstOrFail();
        $productionManager = User::where('email', 'manager.production@tan90.demo')->firstOrFail();
        $qc = User::where('email', 'qc.executive.mfg@tan90.demo')->firstOrFail();
        $qualityManager = User::where('email', 'manager.quality.mfg@tan90.demo')->firstOrFail();

        $finishedGood = FinishedGood::where('code', 'FG-PCM500-BLUE')->firstOrFail();

        // Plan
        $this->actingAs($planner)->post(route('forge.plans.store'), [
            'finished_good_id' => $finishedGood->id,
            'plant' => 'Plant 1',
            'target_qty' => 100,
            'uom' => 'EA',
            'due_date' => now()->addDays(3)->toDateString(),
        ])->assertRedirect();

        $plan = \App\Models\Forge\ProductionPlan::latest()->firstOrFail();
        $this->actingAs($productionManager)->post(route('forge.plans.approve', $plan))->assertRedirect();
        $this->assertSame('frozen', $plan->fresh()->status);

        // Work order (uses the seeded WO-2026-0001 already carrying BOM/Recipe/Routing)
        $wo = WorkOrder::where('wo_number', 'WO-2026-0001')->firstOrFail();
        $this->assertSame('draft', $wo->status);

        $this->actingAs($productionManager)->post(route('forge.workorders.release', $wo))->assertRedirect();
        $this->assertSame('released', $wo->fresh()->status);

        $this->actingAs($supervisor)->post(route('forge.workorders.reserve-material', $wo))->assertRedirect();
        $this->assertSame('material_reserved', $wo->fresh()->status);

        $this->actingAs($supervisor)->post(route('forge.workorders.issue-material', $wo), [
            'lines' => [['item_code' => 'RM-PCM', 'item_name' => 'PCM Raw Compound', 'qty' => 50, 'uom' => 'KG', 'lot_number' => 'LOT-1']],
        ])->assertRedirect();
        $this->assertSame('material_issued', $wo->fresh()->status);

        $this->actingAs($supervisor)->post(route('forge.workorders.start', $wo))->assertRedirect();
        $this->assertSame('in_progress', $wo->fresh()->status);

        // Job cards
        $cards = $wo->jobCards()->orderBy('sequence')->get();
        $this->assertCount(2, $cards);

        $this->actingAs($operator)->post(route('forge.job-cards.start', $cards[0]))->assertRedirect();
        $this->actingAs($operator)->post(route('forge.job-cards.complete', $cards[0]))->assertRedirect();
        $this->assertSame('completed', $cards[0]->fresh()->status);

        // Second card cannot start until first completed - now it should work
        $this->actingAs($operator)->post(route('forge.job-cards.start', $cards[1]))->assertRedirect();
        $this->actingAs($operator)->post(route('forge.job-cards.complete', $cards[1]))->assertRedirect();

        // Production entry
        $this->actingAs($supervisor)->post(route('forge.workorders.record-production', $wo), [
            'good_qty' => 95, 'rework_qty' => 3, 'rejected_qty' => 2,
        ])->assertRedirect();
        $this->assertSame('reconciliation', $wo->fresh()->status);

        $entry = $wo->productionEntries()->latest()->firstOrFail();
        $this->actingAs($productionManager)->post(route('forge.production.approve', $entry))->assertRedirect();
        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertEquals(95, $wo->fresh()->good_qty);

        // Final QC
        $this->actingAs($productionManager)->post(route('forge.workorders.send-to-final-qc', $wo))->assertRedirect();
        $this->assertSame('final_qc_pending', $wo->fresh()->status);

        $this->actingAs($qc)->post(route('forge.final-qc.store', $wo), [
            'accepted_qty' => 90, 'rejected_qty' => 5, 'result' => 'released',
        ])->assertRedirect();

        $result = $wo->finalQcResult()->latest()->firstOrFail();
        $this->actingAs($qualityManager)->post(route('forge.final-qc.release', $result))->assertRedirect();
        $this->assertSame('released_to_fg', $wo->fresh()->status);

        // Batch created and traceable
        $batch = $wo->fresh()->batch;
        $this->assertNotNull($batch);
        $this->assertSame('released', $batch->status);

        $this->actingAs($qc)->get(route('forge.batches.show', $batch))
            ->assertOk()
            ->assertSee($wo->wo_number)
            ->assertSee($finishedGood->name);

        // Close
        $this->actingAs($productionManager)->post(route('forge.workorders.close', $wo))->assertRedirect();
        $this->assertSame('closed', $wo->fresh()->status);
    }

    public function test_ipqc_fail_blocks_job_card_start_until_released(): void
    {
        $operator = User::where('email', 'operator@tan90.demo')->firstOrFail();
        $qc = User::where('email', 'qc.executive.mfg@tan90.demo')->firstOrFail();
        $qualityManager = User::where('email', 'manager.quality.mfg@tan90.demo')->firstOrFail();

        $finishedGood = FinishedGood::where('code', 'FG-PCM500-BLUE')->firstOrFail();
        $wo = WorkOrder::create([
            'wo_number' => 'WO-TEST-IPQC-'.uniqid(),
            'finished_good_id' => $finishedGood->id,
            'target_qty' => 10, 'uom' => 'EA', 'status' => 'in_progress',
        ]);
        $card = $wo->jobCards()->create(['sequence' => 1, 'operation_name' => 'Test Op', 'status' => 'pending']);

        $this->actingAs($qc)->post(route('forge.quality-holds.store'), [
            'work_order_id' => $wo->id, 'checkpoint' => 'Line clearance', 'result' => 'fail',
        ])->assertRedirect();

        $this->actingAs($operator)->post(route('forge.job-cards.start', $card))->assertSessionHasErrors();
        $this->assertSame('pending', $card->fresh()->status);

        $hold = $wo->qualityHolds()->where('status', 'open')->firstOrFail();
        $this->actingAs($qualityManager)->post(route('forge.quality-holds.release', $hold))->assertRedirect();

        $this->actingAs($operator)->post(route('forge.job-cards.start', $card))->assertRedirect();
        $this->assertSame('started', $card->fresh()->status);
    }
}
