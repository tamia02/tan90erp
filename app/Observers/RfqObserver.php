<?php

namespace App\Observers;

use App\Models\Rfq;
use App\Services\ZohoService;
use Illuminate\Support\Facades\Log;

class RfqObserver
{
    public function saved(Rfq $rfq): void
    {
        try {
            app(ZohoService::class)->pushRfq($rfq);
        } catch (\Throwable $exception) {
            Log::warning('Zoho Quote push failed from RFQ observer', [
                'rfq_id' => $rfq->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
