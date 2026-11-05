<?php

namespace App\Services\Zoho;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// GET-only Zoho Inventory client for the Zoho -> ERP pulls (items, recipes).
// Credentials: config('services.zoho_items_pull'). Never sends writes.
class ZohoReadClient
{
    public int $calls = 0;

    // Pause between calls so a big pull stays well under Zoho's per-minute limit.
    public int $pauseMs = 0;

    public function configured(): bool
    {
        $c = config('services.zoho_items_pull');

        return filled($c['organization_id'] ?? null) && filled($c['refresh_token'] ?? null)
            && filled($c['client_id'] ?? null) && filled($c['client_secret'] ?? null);
    }

    public function get(string $path, array $query = [], ?ZohoApiGate $gate = null): array
    {
        $c = config('services.zoho_items_pull');
        $token = $this->accessToken();
        if ($gate && ($reason = $gate->acquire())) {
            throw new RuntimeException($reason);
        }
        if ($this->pauseMs > 0 && $this->calls > 0) {
            usleep($this->pauseMs * 1000);
        }
        $this->calls++;

        $response = Http::withHeaders(['Authorization' => 'Zoho-oauthtoken '.$token])
            ->timeout(40)->retry($gate ? 1 : 2, 2000, throw: false)
            ->get(rtrim($c['api_base_url'], '/').$path, $query + ['organization_id' => $c['organization_id']]);

        if ($gate) {
            $code = (int) ($response->json('code') ?? 0);
            $outcome = $response->successful() && $code === 0 ? ZohoOutcome::Success
                : (($code === 45 || $response->status() === 429 || $response->serverError()) ? ZohoOutcome::Transient : ZohoOutcome::Permanent);
            $gate->record(new ZohoResult($outcome, $response->status(), $response->json() ?? [], (string) ($response->json('message') ?? '')));
        }

        if (! $response->successful()) {
            throw new RuntimeException("Zoho GET {$path} failed ({$response->status()}): ".($response->json('message') ?? 'no message'));
        }

        return $response->json() ?? [];
    }

    /** Every row of a paged list endpoint. @return array<int, array> */
    public function all(string $path, string $key, array $query = [], int $maxPages = 60): array
    {
        $rows = [];
        for ($page = 1; $page <= $maxPages; $page++) {
            $body = $this->get($path, $query + ['page' => $page, 'per_page' => 200]);
            $rows = [...$rows, ...($body[$key] ?? [])];
            if (! ($body['page_context']['has_more_page'] ?? false)) {
                break;
            }
        }

        return $rows;
    }

    private function accessToken(): string
    {
        $c = config('services.zoho_items_pull');

        return Cache::remember('zoho_items_pull_token:'.md5($c['client_id'].$c['organization_id']), now()->addMinutes(50), function () use ($c) {
            $this->calls++;
            $response = Http::asForm()->timeout(20)->post(rtrim($c['accounts_url'], '/').'/oauth/v2/token', [
                'refresh_token' => $c['refresh_token'], 'client_id' => $c['client_id'],
                'client_secret' => $c['client_secret'], 'grant_type' => 'refresh_token',
            ]);
            $token = $response->json('access_token');
            if (! $token) {
                throw new RuntimeException('Zoho token refresh failed: '.($response->json('error') ?? $response->status()));
            }

            return $token;
        });
    }
}
