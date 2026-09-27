<?php

namespace Tests\Unit;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Services\PurchaseOrderAmountCalculator;
use Tests\TestCase;

class PurchaseOrderAmountCalculatorTest extends TestCase
{
    public function test_line_level_gst_is_included_and_prorated_for_partial_shipment(): void
    {
        $po = PurchaseOrder::make(['tax' => 0, 'discount' => 0, 'adjustment' => 0, 'reverse_charge' => false]);
        $line = PurchaseOrderLine::make(['product' => 'Thermocol', 'quantity' => 100, 'list_price' => 42, 'discount' => 0, 'tax' => 756]);
        $line->setAttribute('id', 101);
        $po->setRelation('lines', collect([$line]));
        $calculator = app(PurchaseOrderAmountCalculator::class);

        $full = $calculator->amounts($po, [101 => 100]);
        $partial = $calculator->amounts($po, [101 => 40]);

        $this->assertSame(['net' => 4200.0, 'tax' => 756.0, 'total' => 4956.0], $full);
        $this->assertSame(['net' => 1680.0, 'tax' => 302.4, 'total' => 1982.4], $partial);
    }

    public function test_allocations_map_each_shipped_sku_to_its_po_line(): void
    {
        $po = PurchaseOrder::make(['tax' => 0, 'discount' => 0, 'adjustment' => 0, 'reverse_charge' => false]);
        $first = PurchaseOrderLine::make(['product' => 'Resin', 'quantity' => 100, 'list_price' => 10, 'discount' => 0, 'tax' => 180]);
        $first->setAttribute('id', 11);
        $second = PurchaseOrderLine::make(['product' => 'Colorant', 'quantity' => 20, 'list_price' => 25, 'discount' => 0, 'tax' => 90]);
        $second->setAttribute('id', 12);
        $po->setRelation('lines', collect([$first, $second]));
        $calculator = app(PurchaseOrderAmountCalculator::class);

        $allocated = $calculator->allocations($po, [
            ['po_line_id' => 11, 'sku' => 'Resin', 'qty' => 40],
            ['po_line_id' => 12, 'sku' => 'Colorant', 'qty' => 10],
        ]);

        $this->assertSame([11 => 40.0, 12 => 10.0], $allocated);
        $this->assertSame(['net' => 650.0, 'tax' => 117.0, 'total' => 767.0], $calculator->amounts($po, $allocated));
    }
}
