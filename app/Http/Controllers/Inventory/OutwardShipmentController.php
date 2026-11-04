<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Outward\OutwardShipment;
use Illuminate\View\View;

class OutwardShipmentController extends Controller
{
    public function show(OutwardShipment $shipment): View
    {
        $shipment->load(['lines.item', 'lines.packagingType', 'gateEntry', 'creator']);

        return view('inventory.outward-show', compact('shipment'));
    }
}
