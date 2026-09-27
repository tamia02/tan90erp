<?php

namespace Tests\Feature\Tan90\MasterData;

use App\Models\Tan90\MasterData\MasterAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_cannot_be_updated(): void
    {
        $log = MasterAuditLog::create(['event' => 'CREATE', 'module' => 'Test', 'summary' => 'Initial.']);

        $this->expectException(LogicException::class);
        $log->update(['summary' => 'Tampered.']);
    }

    public function test_audit_log_cannot_be_deleted(): void
    {
        $log = MasterAuditLog::create(['event' => 'CREATE', 'module' => 'Test', 'summary' => 'Initial.']);

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_no_write_routes_are_registered_for_the_audit_trail(): void
    {
        $routeNames = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->map(fn ($route) => $route->getName());

        $this->assertFalse($routeNames->contains('tan90.master-data.audit-logs.update'));
        $this->assertFalse($routeNames->contains('tan90.master-data.audit-logs.destroy'));
    }
}
