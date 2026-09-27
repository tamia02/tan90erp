<?php

namespace Tests\Unit;

use App\Services\PurchaseOrderTaxService;
use Tests\TestCase;

class PurchaseOrderTaxServiceTest extends TestCase
{
    public function test_po_tax_accepts_full_state_names_and_common_gst_codes(): void
    {
        $service = app(PurchaseOrderTaxService::class);

        $this->assertSame('09', $service->stateCode('UP'));
        $this->assertSame('27', $service->stateCode('MH'));
        $this->assertSame('33', $service->stateCode('Tamil Nadu'));
        $this->assertSame('21', $service->stateCode('OR'));
        $this->assertNull($service->stateCode('Not a state'));
    }

    public function test_unmapped_place_of_supply_fails_safe_without_blocking_tax_breakdown(): void
    {
        $service = app(PurchaseOrderTaxService::class);

        $breakdown = $service->breakdown(100, 0, 18, 'Any temporary state label');

        $this->assertSame(100.0, $breakdown['taxable']);
        $this->assertSame(0.0, $breakdown['cgst']);
        $this->assertSame(0.0, $breakdown['sgst']);
        $this->assertSame(0.0, $breakdown['igst']);
    }
}
