<?php

namespace App\Services\Outward;

use App\Models\GateEntry;
use App\Models\Outward\OutwardShipment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Guard lets a loaded outward truck leave. When the pass carries an Outward
// Shipment, the truck leaving IS the dispatch: stock goes down now, all lines
// or none; missing documents or short stock keep the truck inside.
class GateExitService
{
    public function __construct(private OutwardShipmentService $shipments) {}

    public function confirm(GateEntry $gate, User $user): ?OutwardShipment
    {
        if ($gate->entry_type !== 'outward' || $gate->status !== 'loaded') {
            throw ValidationException::withMessages(['exit' => "{$gate->gate_no} is not loaded yet - it can leave once the Store Executive completes loading."]);
        }
        $shipment = $gate->outwardShipment;
        try {
            DB::transaction(function () use ($gate, $shipment, $user) {
                if ($shipment && $shipment->status !== 'dispatched') {
                    $this->shipments->markDispatched($shipment, $user);
                }
                $gate->update(['status' => 'dispatched', 'exited_at' => now()]);
            });
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['exit' => collect($e->errors())->flatten()->implode(' ')]);
        }
        AuditLogger::log('Outward vehicle exited premises', $gate->gate_no.($shipment ? " · {$shipment->number} dispatched" : ''), $gate);

        return $shipment;
    }
}
