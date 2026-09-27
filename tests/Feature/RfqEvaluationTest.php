<?php

namespace Tests\Feature;

use App\Models\Rfq;
use App\Models\User;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RfqEvaluationTest extends TestCase
{
    public function test_admin_can_evaluate_a_quoted_rfq_and_see_the_weighted_score(): void
    {
        $admin = User::where('email', 'admin@tan90.test')->firstOrFail();
        $rfq = Rfq::create([
            'vendor_name' => 'Test Vendor', 'sku' => 'Test SKU', 'quantity' => 10,
            'status' => 'quoted', 'quoted_price' => 100,
        ]);

        $this->actingAs($admin);

        Volt::test('admin.rfq')
            ->set('technicalScore', 80)
            ->set('commercialScore', 60)
            ->call('evaluate', $rfq->id)
            ->assertHasNoErrors();

        $rfq->refresh();
        $this->assertSame(80, $rfq->technical_score);
        $this->assertSame(60, $rfq->commercial_score);
        $this->assertNotNull($rfq->evaluated_at);
        // 80*0.6 + 60*0.4 = 48 + 24 = 72
        $this->assertEquals(72.0, $rfq->weightedScore());

        $rfq->delete();
    }

    public function test_evaluation_score_must_be_within_0_to_100(): void
    {
        $admin = User::where('email', 'admin@tan90.test')->firstOrFail();
        $rfq = Rfq::create([
            'vendor_name' => 'Test Vendor', 'sku' => 'Test SKU', 'quantity' => 10,
            'status' => 'quoted', 'quoted_price' => 100,
        ]);

        $this->actingAs($admin);

        Volt::test('admin.rfq')
            ->set('technicalScore', 150)
            ->set('commercialScore', 60)
            ->call('evaluate', $rfq->id)
            ->assertHasErrors('technicalScore');

        $this->assertNull($rfq->fresh()->technical_score);

        $rfq->delete();
    }
}
