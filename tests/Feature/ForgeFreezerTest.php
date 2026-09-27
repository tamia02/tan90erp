<?php

namespace Tests\Feature;

use App\Models\Forge\Batch;
use App\Models\Forge\Freezer;
use App\Models\Forge\FreezerLog;
use App\Models\Forge\WorkOrder;
use App\Models\Tan90\BomRecipeCosting\FinishedGood;
use App\Models\User;
use App\Models\Workspace\WorkspaceException;
use App\Services\Forge\FreezerMonitoringService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Space 05's blast-freezer module (spec module 11) — occupancy, temperature
 * readings, and the alerting integration into the shared Workspace Alerts &
 * Exceptions queue. Not RefreshDatabase: relies on the persistent demo
 * hierarchy (head.manufacturing@tan90.demo etc.) and the two seeded freezers,
 * matching the pattern used by ForgeGoldenPathTest.
 */
class ForgeFreezerTest extends TestCase
{
    /**
     * A throwaway work order dedicated to this test file's own batches.
     * Previously these tests reused WorkOrder::first(), which happens to be
     * the same seeded WO-2026-0001 that ForgeGoldenPathTest drives through
     * its full lifecycle — since Batch is an unordered hasOne, attaching a
     * stray 'in_process' batch to that shared work order made
     * ForgeGoldenPathTest's own freshly-released batch invisible behind it.
     */
    private function dedicatedWorkOrder(string $suffix): WorkOrder
    {
        $finishedGood = FinishedGood::where('code', 'FG-PCM500-BLUE')->firstOrFail();

        return WorkOrder::create([
            'wo_number' => 'WO-TEST-FREEZER-'.$suffix.'-'.uniqid(),
            'finished_good_id' => $finishedGood->id,
            'target_qty' => 100,
            'uom' => 'NOS',
            'status' => 'in_progress',
        ]);
    }

    public function test_freezer_dashboard_loads_for_manufacturing_head(): void
    {
        $head = User::where('email', 'head.manufacturing@tan90.demo')->firstOrFail();

        $this->actingAs($head)->get(route('forge.freezers.index'))->assertOk();
    }

    public function test_assigning_and_releasing_a_batch_tracks_occupancy(): void
    {
        $freezer = Freezer::where('code', 'BF-PCM-01')->firstOrFail();
        $wo = $this->dedicatedWorkOrder('A');
        $batch = Batch::firstOrCreate(['batch_number' => 'B-TEST-FREEZER-001'], [
            'work_order_id' => $wo->id, 'qty' => 100, 'uom' => 'NOS', 'status' => 'in_process',
        ]);

        $service = app(FreezerMonitoringService::class);
        $log = $service->assignBatch($freezer, $batch);

        $this->assertNull($log->ended_at);
        $this->assertSame('running', $freezer->fresh()->state);

        $released = $service->releaseBatch($log);

        $this->assertNotNull($released->ended_at);
        $this->assertSame('idle', $freezer->fresh()->state);
    }

    public function test_cannot_assign_a_batch_to_a_freezer_already_occupied(): void
    {
        $freezer = Freezer::where('code', 'BF-PCM-02')->firstOrFail();
        $wo = $this->dedicatedWorkOrder('B');
        $batchOne = Batch::firstOrCreate(['batch_number' => 'B-TEST-FREEZER-002'], [
            'work_order_id' => $wo->id, 'qty' => 100, 'uom' => 'NOS', 'status' => 'in_process',
        ]);
        $batchTwo = Batch::firstOrCreate(['batch_number' => 'B-TEST-FREEZER-003'], [
            'work_order_id' => $wo->id, 'qty' => 100, 'uom' => 'NOS', 'status' => 'in_process',
        ]);

        $service = app(FreezerMonitoringService::class);
        $log = $service->assignBatch($freezer, $batchOne);

        try {
            $service->assignBatch($freezer, $batchTwo);
            $this->fail('Expected a ValidationException when assigning a second batch to an occupied freezer.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('freezer', $e->errors());
        } finally {
            $service->releaseBatch($log->fresh());
        }
    }

    public function test_out_of_range_reading_raises_a_workspace_exception_only_once(): void
    {
        $freezer = Freezer::where('code', 'BF-PCM-01')->firstOrFail();
        WorkspaceException::where('linked_type', Freezer::class)->where('linked_id', $freezer->id)->delete();

        $service = app(FreezerMonitoringService::class);

        $first = $service->recordReading($freezer, -5.0);
        $this->assertTrue($first->is_alert);
        $this->assertSame(1, WorkspaceException::where('linked_type', Freezer::class)->where('linked_id', $freezer->id)->count());

        // A second breach while the exception is still open must not create a duplicate.
        $second = $service->recordReading($freezer, -4.0);
        $this->assertTrue($second->is_alert);
        $this->assertSame(1, WorkspaceException::where('linked_type', Freezer::class)->where('linked_id', $freezer->id)->count());

        $inRange = $service->recordReading($freezer, -20.0);
        $this->assertFalse($inRange->is_alert);

        WorkspaceException::where('linked_type', Freezer::class)->where('linked_id', $freezer->id)->delete();
    }
}
