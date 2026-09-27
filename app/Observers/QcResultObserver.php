<?php

namespace App\Observers;

use App\Models\QcResult;
use App\Services\ZohoService;
use Illuminate\Support\Facades\Log;

class QcResultObserver
{
    public function saved(QcResult $result): void
    {
        try {
            app(ZohoService::class)->pushQcResult($result);
        } catch (\Throwable $exception) {
            Log::warning('Zoho note push failed from QcResult observer', ['qc_result_id' => $result->id, 'error' => $exception->getMessage()]);
        }
    }
}
