<?php

namespace Tests\Feature;

use App\Models\Forge\Deviation;
use App\Models\Forge\WorkOrder;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Models\User;
use Tests\TestCase;

/**
 * Not RefreshDatabase: relies on the persistent demo hierarchy (matches
 * ForgeGoldenPathTest / ForgeFreezerTest) rather than hand-rolling access
 * grants, which would duplicate — and risk drifting from — how
 * AccessControlSeeder actually wires roles/permissions together.
 */
class ForgeReworkAndYieldTest extends TestCase
{
    public function test_a_rework_disposed_deviation_can_spawn_an_executable_work_order(): void
    {
        $manager = User::where('email', 'manager.production@tan90.demo')->firstOrFail();
        $finishedGood = FinishedGood::where('code', 'FG-PCM500-BLUE')->firstOrFail();

        $source = WorkOrder::create([
            'wo_number' => 'WO-TEST-SOURCE-'.uniqid(),
            'finished_good_id' => $finishedGood->id,
            'target_qty' => 500, 'good_qty' => 480, 'rework_qty' => 20, 'uom' => 'EA', 'status' => 'reconciliation',
        ]);
        $deviation = Deviation::create([
            'work_order_id' => $source->id, 'source_type' => 'quality',
            'description' => 'Seal peel strength below spec', 'qty' => 20, 'uom' => 'EA',
            'disposition' => 'rework', 'status' => 'disposed', 'opened_by' => $manager->id,
        ]);

        $response = $this->actingAs($manager)->post(route('forge.deviations.rework-order', $deviation));

        $reworkWo = WorkOrder::where('source_deviation_id', $deviation->id)->first();
        $this->assertNotNull($reworkWo);
        $this->assertEquals(20, (float) $reworkWo->target_qty);
        $this->assertSame('draft', $reworkWo->status);
        $response->assertRedirect(route('forge.workorders.show', $reworkWo));

        // A second attempt must not create a duplicate rework order.
        $this->actingAs($manager)->post(route('forge.deviations.rework-order', $deviation))->assertStatus(422);
        $this->assertSame(1, WorkOrder::where('source_deviation_id', $deviation->id)->count());
    }

    public function test_yield_dashboard_computes_scrap_percentage(): void
    {
        $manager = User::where('email', 'manager.production@tan90.demo')->firstOrFail();
        $finishedGood = FinishedGood::where('code', 'FG-PCM500-BLUE')->firstOrFail();

        WorkOrder::create([
            'wo_number' => 'WO-TEST-YIELD-'.uniqid(),
            'finished_good_id' => $finishedGood->id,
            'target_qty' => 100, 'good_qty' => 90, 'rework_qty' => 5, 'rejected_qty' => 5, 'uom' => 'EA', 'status' => 'closed',
        ]);

        $response = $this->actingAs($manager)->get(route('forge.yield.index'));

        $response->assertOk();
        $response->assertSee('10%');
    }
}
