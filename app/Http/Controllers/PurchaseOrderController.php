<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function show(PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder->load(['lines', 'acknowledgement', 'gateEntries.unloadingRecord', 'gateEntries.qcResult', 'gateEntries.grnRecord', 'gateEntries.putawayBy']);
        $gates = $purchaseOrder->gateEntries->sortByDesc('created_at')->values();
        $postedGates = $gates->filter(fn ($gate) => $gate->grnRecord?->posted);
        $lifecycleStatus = match (true) {
            $postedGates->isNotEmpty() && $postedGates->every(fn ($gate) => $gate->putaway_completed_at !== null) => 'Put away done',
            $postedGates->isNotEmpty() => 'GRN posted · Putaway pending',
            $gates->isNotEmpty() => \App\Support\GateStatusLabels::label($gates->first()->status),
            default => $purchaseOrder->status ?: 'Created',
        };

        return view('purchase-orders.show', compact('purchaseOrder', 'gates', 'lifecycleStatus'));
    }
}
