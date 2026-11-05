<?php

namespace App\Services\Zoho;

use App\Models\ZohoSalesDocument;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ZohoSalesPullService
{
    public function __construct(private ZohoReadClient $zoho, private ZohoApiGate $gate) {}

    public function pull(int $maxPages = 10, bool $dryRun = false, ?string $module = null): array
    {
        if (! $this->zoho->configured()) {
            throw new RuntimeException('Zoho pull credentials are missing.');
        }
        $perMinute = (int) config('services.zoho.inventory.rate_limit.per_minute', 60);
        if ($perMinute > 0) {
            $this->zoho->pauseMs = max($this->zoho->pauseMs, (int) ceil(60000 / $perMinute) + 50);
        }
        $org = (string) config('services.zoho_items_pull.organization_id');
        $stats = [];
        $modules = ['salesorders' => 'salesorder', 'packages' => 'package', 'invoices' => 'invoice'];
        if ($module !== null && ! isset($modules[$module])) {
            throw new RuntimeException('Unknown sales module.');
        }
        foreach ($modules as $endpoint => $type) {
            if ($module !== null && $endpoint !== $module) {
                continue;
            }
            $key = "zoho:sales-pull:{$org}:{$endpoint}:page";
            $page = $dryRun ? 1 : (int) Cache::get($key, 1);
            $count = 0;
            $more = false;
            for ($i = 0; $i < $maxPages; $i++, $page++) {
                $body = $this->zoho->get('/'.$endpoint, ['page' => $page, 'per_page' => 200], $this->gate);
                if (($body['code'] ?? 0) != 0 || ! isset($body[$endpoint]) || ! is_array($body[$endpoint])) {
                    throw new RuntimeException("Invalid Zoho {$endpoint} response; page {$page} was not advanced.");
                }
                $records = [];
                foreach ($body[$endpoint] as $row) {
                    if (empty($row[$type.'_id'])) {
                        throw new RuntimeException("Zoho {$endpoint} record is missing its ID.");
                    }
                    if (! $dryRun) {
                        $records[] = [
                            'organization_id' => $org, 'type' => $type, 'zoho_id' => (string) $row[$type.'_id'],
                            'number' => $row[$type.'_number'] ?? null,
                            'salesorder_id' => $row['salesorder_id'] ?? null,
                            'salesorder_number' => $row['salesorder_number'] ?? null,
                            'customer_name' => $row['customer_name'] ?? null,
                            'status' => $row['status'] ?? null,
                            'document_date' => ($row['date'] ?? null) ?: null,
                            'total' => $row['total'] ?? null,
                            'payload' => json_encode($row, JSON_THROW_ON_ERROR), 'synced_at' => now(),
                        ];
                    }
                    $count++;
                }
                if ($records !== []) {
                    ZohoSalesDocument::upsert($records, ['organization_id', 'type', 'zoho_id'], [
                        'number', 'salesorder_id', 'salesorder_number', 'customer_name', 'status',
                        'document_date', 'total', 'payload', 'synced_at', 'updated_at',
                    ]);
                }
                $more = (bool) ($body['page_context']['has_more_page'] ?? false);
                if (! $dryRun) {
                    Cache::forever($key, $more ? $page + 1 : 1);
                }
                if (! $more) {
                    break;
                }
            }
            $stats[$endpoint] = ['imported' => $count, 'more' => $more];
        }

        return $stats;
    }
}
