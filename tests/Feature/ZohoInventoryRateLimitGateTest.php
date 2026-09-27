<?php

namespace Tests\Feature;

use App\Services\ZohoInventoryService;
use App\Services\Zoho\ZohoApiGate;
use App\Services\Zoho\ZohoOutcome;
use App\Services\Zoho\ZohoResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoInventoryRateLimitGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('services.zoho.inventory.sync_enabled', true);
        config()->set('services.zoho.inventory.rate_limit.per_minute', 0);
        config()->set('services.zoho.inventory.rate_limit.per_run', 120);
        config()->set('services.zoho.inventory.rate_limit.per_day', 800);
        config()->set('services.zoho.inventory.breaker.cooldown_ladder', [30, 60, 180, 360]);
    }

    public function test_disabled_gate_blocks_every_inventory_http_request_locally(): void
    {
        config()->set('services.zoho.inventory.organization_id', 'local-test-org');
        config()->set('services.zoho.inventory.refresh_token', 'local-test-refresh');
        config()->set('services.zoho.inventory.sync_enabled', false);
        Cache::put('zoho_inventory_access_token', 'local-test-token', 3300);
        Http::fake();

        $result = app(ZohoInventoryService::class)->syncMasterData(200);

        Http::assertNothingSent();
        $this->assertSame(0, $result['vendors']);
        $this->assertSame(0, $result['items']);
    }

    public function test_daily_budget_is_a_hard_ceiling_across_gate_instances(): void
    {
        config()->set('services.zoho.inventory.rate_limit.per_day', 1);

        $firstWorker = app(ZohoApiGate::class);
        $secondWorker = app(ZohoApiGate::class);

        $this->assertNull($firstWorker->acquire());
        $this->assertStringContainsString('daily API budget spent', (string) $secondWorker->acquire());
        $this->assertSame(1, $secondWorker->dailyUsage());
    }

    public function test_inventory_service_middleware_enforces_budget_before_second_endpoint(): void
    {
        config()->set('services.zoho.inventory.organization_id', 'local-test-org');
        config()->set('services.zoho.inventory.refresh_token', 'local-test-refresh');
        config()->set('services.zoho.inventory.rate_limit.per_day', 1);
        Cache::put('zoho_inventory_access_token', 'local-test-token', 3300);
        Http::fake([
            '*contacts*' => Http::response(['code' => 0, 'contacts' => []], 200),
            '*items*' => Http::response(['code' => 0, 'items' => []], 200),
        ]);

        app(ZohoInventoryService::class)->syncMasterData(200);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/contacts'));
        $this->assertSame(1, app(ZohoApiGate::class)->dailyUsage());
    }

    public function test_per_run_budget_stops_the_current_worker(): void
    {
        config()->set('services.zoho.inventory.rate_limit.per_run', 2);
        $gate = app(ZohoApiGate::class);

        $this->assertNull($gate->acquire());
        $this->assertNull($gate->acquire());
        $this->assertStringContainsString('per-run API budget spent', (string) $gate->acquire());
        $this->assertSame(2, $gate->snapshot()['run_calls']);
    }

    public function test_code_45_opens_the_breaker_and_blocks_follow_up_calls(): void
    {
        $gate = app(ZohoApiGate::class);
        $this->assertNull($gate->acquire());

        $gate->record(new ZohoResult(
            ZohoOutcome::Transient,
            429,
            ['code' => 45, 'message' => 'Exceeded the maximum call rate limit'],
            'Exceeded the maximum call rate limit',
        ));

        $snapshot = $gate->snapshot();
        $this->assertSame(ZohoApiGate::STATE_OPEN, $snapshot['state']);
        $this->assertSame(1, $snapshot['level']);
        $this->assertStringContainsString('circuit breaker open', (string) $gate->acquire());
    }

    public function test_structural_rate_limit_detection_handles_spacing_and_http_429(): void
    {
        $gate = app(ZohoApiGate::class);

        $this->assertTrue($gate->looksRateLimited(200, ['code' => 45], '{"code": 45}'));
        $this->assertTrue($gate->looksRateLimited(429, [], ''));
        $this->assertSame(
            ZohoOutcome::Transient,
            $gate->classify(200, ['code' => 45], '{"code": 45}'),
        );
    }
}
