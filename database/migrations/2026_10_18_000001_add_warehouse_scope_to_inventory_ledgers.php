<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->after('location_id')->constrained('tan90_warehouses')->nullOnDelete();
            $table->index(['warehouse_id', 'item_id', 'type'], 'inventory_movements_warehouse_item_type_idx');
        });

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->after('gate_entry_id')->constrained('tan90_warehouses')->nullOnDelete();
            $table->index(['warehouse_id', 'sku', 'bucket'], 'ledger_entries_warehouse_sku_bucket_idx');
        });

        Schema::table('outward_shipment_lines', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->after('item_id')->constrained('tan90_warehouses')->nullOnDelete();
            $table->index(['warehouse_id', 'item_id'], 'outward_lines_warehouse_item_idx');
        });

        $warehouses = DB::table('tan90_warehouses')->where('status', 'active')->whereNull('deleted_at')->orderBy('id')->get();
        if ($warehouses->isEmpty()) {
            return;
        }

        $locations = DB::table('tan90_locations')->get(['id', 'code', 'name', 'city']);
        $defaultLocationId = $locations->firstWhere('code', 'LOC-BHW')?->id ?? $locations->first()?->id;
        $legacyAliases = [
            'LOC-BHW' => ['mumbai'],
            'LOC-DEL' => ['pali', 'faridabad'],
        ];
        foreach (DB::table('gate_entries')->whereNull('location_id')->get(['id', 'location']) as $gate) {
            $label = mb_strtolower(trim((string) $gate->location));
            $matched = null;
            foreach ($locations as $location) {
                $tokens = array_filter([
                    mb_strtolower(trim((string) $location->code)),
                    mb_strtolower(trim((string) $location->name)),
                    mb_strtolower(trim((string) $location->city)),
                    ...($legacyAliases[$location->code] ?? []),
                ], fn ($token) => mb_strlen($token) >= 4);
                if ($label !== '' && collect($tokens)->contains(fn ($token) => str_contains($label, $token))) {
                    $matched = (int) $location->id;
                    break;
                }
            }
            $locationId = $matched ?: (blank($label) ? (int) $defaultLocationId : null);
            if ($locationId) {
                DB::table('gate_entries')->where('id', $gate->id)->update(['location_id' => $locationId]);
            }
        }
        DB::table('inventory_movements')->whereNull('location_id')->update(['location_id' => $defaultLocationId]);

        $warehouseFor = static function (?int $locationId, ?string $type) use ($warehouses): ?int {
            $atLocation = $warehouses->where('tan90_location_id', $locationId);
            if ($atLocation->isEmpty()) {
                return null;
            }
            $desired = in_array($type, ['rm', 'pm'], true) ? 'Raw Material' : 'Finished Goods';
            $match = $atLocation->first(fn ($warehouse) => $warehouse->warehouse_type === $desired);
            $match ??= $atLocation->first(fn ($warehouse) => $warehouse->warehouse_type === 'Central Distribution');

            return (int) ($match ?? $atLocation->first())->id;
        };

        $movementGroups = DB::table('inventory_movements')->join('inventory_items', 'inventory_items.id', '=', 'inventory_movements.item_id')
            ->whereNull('inventory_movements.warehouse_id')->select('inventory_movements.location_id', 'inventory_items.item_type')->distinct()->get();
        foreach ($movementGroups as $group) {
            $warehouseId = $warehouseFor($group->location_id, $group->item_type);
            if ($warehouseId) {
                DB::table('inventory_movements')->whereNull('warehouse_id')->where('location_id', $group->location_id)
                    ->whereIn('item_id', DB::table('inventory_items')->select('id')->where('item_type', $group->item_type))
                    ->update(['warehouse_id' => $warehouseId]);
            }
        }

        $ledgerGroups = DB::table('ledger_entries')->join('gate_entries', 'gate_entries.id', '=', 'ledger_entries.gate_entry_id')
            ->join('inventory_items', 'inventory_items.sku', '=', 'ledger_entries.sku')->whereNull('ledger_entries.warehouse_id')
            ->select('gate_entries.location_id', 'inventory_items.item_type')->distinct()->get();
        foreach ($ledgerGroups as $group) {
            $warehouseId = $warehouseFor($group->location_id, $group->item_type);
            if ($warehouseId) {
                DB::table('ledger_entries')->whereNull('warehouse_id')
                    ->whereIn('gate_entry_id', DB::table('gate_entries')->select('id')->where('location_id', $group->location_id))
                    ->whereIn('sku', DB::table('inventory_items')->select('sku')->where('item_type', $group->item_type))
                    ->update(['warehouse_id' => $warehouseId]);
            }
        }

        $outwardGroups = DB::table('outward_shipment_lines')->join('outward_shipments', 'outward_shipments.id', '=', 'outward_shipment_lines.outward_shipment_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'outward_shipment_lines.item_id')->whereNull('outward_shipment_lines.warehouse_id')
            ->whereNotNull('outward_shipments.from_location_id')->select('outward_shipments.from_location_id', 'inventory_items.item_type')->distinct()->get();
        foreach ($outwardGroups as $group) {
            $warehouseId = $warehouseFor($group->from_location_id, $group->item_type);
            if ($warehouseId) {
                DB::table('outward_shipment_lines')->whereNull('warehouse_id')
                    ->whereIn('outward_shipment_id', DB::table('outward_shipments')->select('id')->where('from_location_id', $group->from_location_id))
                    ->whereIn('item_id', DB::table('inventory_items')->select('id')->where('item_type', $group->item_type))
                    ->update(['warehouse_id' => $warehouseId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('outward_shipment_lines', function (Blueprint $table): void {
            $table->dropIndex('outward_lines_warehouse_item_idx');
            $table->dropConstrainedForeignId('warehouse_id');
        });
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropIndex('ledger_entries_warehouse_sku_bucket_idx');
            $table->dropConstrainedForeignId('warehouse_id');
        });
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropIndex('inventory_movements_warehouse_item_type_idx');
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
