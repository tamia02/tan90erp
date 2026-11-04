<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryProduction;
use App\Services\AuditLogger;
use App\Services\Inventory\InventoryService;
use App\Support\SiteLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryBundleController extends Controller
{
    public function show(InventoryProduction $production): View
    {
        SiteLocation::assertCanAccessSiteRecord(auth()->user(), $production->location_id);
        $production->load(['fgItem', 'movements.item', 'creator']);

        return view('inventory.bundle-show', compact('production'));
    }

    public function edit(InventoryProduction $production): View
    {
        SiteLocation::assertCanAccessSiteRecord(auth()->user(), $production->location_id);
        $production->load(['fgItem', 'creator']);

        return view('inventory.bundle-edit', compact('production'));
    }

    public function update(Request $request, InventoryProduction $production, InventoryService $inventory): RedirectResponse
    {
        SiteLocation::assertCanAccessSiteRecord($request->user(), $production->location_id);
        $values = $request->validate([
            'qty_produced' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'production_date' => ['nullable', 'date'],
        ]);
        $before = (float) $production->qty_produced;
        $beforeDate = ($production->production_date ?? $production->created_at)?->toDateString();
        $updated = $inventory->reviseProduction($production, (float) $values['qty_produced'], $values['notes'] ?? null, $request->user(), $values['production_date'] ?? null);
        AuditLogger::log('Production bundle revised', "{$updated->number} · quantity {$before} → {$updated->qty_produced} · assembly date {$beforeDate} → {$updated->production_date?->toDateString()}", $updated);

        return redirect()->route('inventory.bundles.show', $updated)->with('success', "{$updated->number} updated and stock movements recalculated.");
    }
}
