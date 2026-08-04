<?php

namespace App\Observers;

use App\Models\SkuMaster;
use App\Services\ZohoService;
use Illuminate\Support\Facades\Log;

class SkuMasterObserver
{
    public function saved(SkuMaster $sku): void
    {
        try {
            app(ZohoService::class)->pushSkuMaster($sku);
        } catch (\Throwable $exception) {
            Log::warning('Zoho Product push failed from SkuMaster observer', [
                'sku_id' => $sku->id,
                'sku' => $sku->sku,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
