<?php

namespace App\Http\Controllers\Tan90\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Tan90\MasterData\IntegrationConnection;
use App\Services\Tan90\MasterData\AuditLogger;
use App\Services\Tan90\MasterData\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Backs the "POST API connection test" special route. Does a real HTTP GET
 * against `base_url` when one is configured; connections with no base_url
 * (SMTP-only rows, or the disabled Zoho placeholder) stay 'pending'/'disabled'
 * rather than being marked healthy on faith.
 */
class IntegrationConnectionController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function test(Request $request, IntegrationConnection $connection)
    {
        abort_unless($this->permissions->can($request->user(), 'settings'), 403);

        if ($connection->status !== 'active' || ! $connection->base_url || ! str_starts_with($connection->base_url, 'http')) {
            $connection->update(['health' => 'disabled', 'last_tested_at' => now()]);

            return back()->with('status', "{$connection->name}: no reachable base URL configured, marked disabled.");
        }

        try {
            $response = Http::timeout(8)->get($connection->base_url);
            $health = $response->successful() ? 'healthy' : 'failed';
        } catch (Throwable $e) {
            $health = 'failed';
        }

        $connection->update(['health' => $health, 'last_tested_at' => now()]);
        $this->auditLogger->log('CONNECTION_TEST', $connection, "Tested {$connection->name}: {$health}.");

        return back()->with('status', "{$connection->name}: {$health}.");
    }
}
