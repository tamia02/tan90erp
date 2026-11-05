<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Meeting 2026-10-08: units must come from one dropdown (no KG vs KGS).
// Adds every standard unit the Zoho items use to the Units master, and
// tidies the few odd spellings already stored. Inserts only - nothing removed.
return new class extends Migration
{
    private const UNITS = [
        ['KG', 'Kilogram', 'Weight', 'KG'], ['GM', 'Gram', 'Weight', 'KG'], ['NOS', 'Numbers', 'Count', 'NOS'],
        ['PCS', 'Pieces', 'Count', 'PCS'], ['BOX', 'Box', 'Count', 'BOX'], ['LTR', 'Litre', 'Volume', 'LTR'],
        ['MTR', 'Metre', 'Length', 'MTR'], ['FT', 'Feet', 'Length', 'MTR'], ['ROLL', 'Roll', 'Count', 'ROLL'],
        ['SET', 'Set', 'Count', 'SET'], ['OTH', 'Others', 'Count', 'OTH'],
    ];

    public function up(): void
    {
        foreach (self::UNITS as [$code, $name, $type, $base]) {
            DB::table('tan90_uoms')->insertOrIgnore([
                'code' => $code, 'name' => $name, 'type' => $type, 'base_uom' => $base, 'conversion_factor' => 1,
                'decimal_places' => $type === 'Count' ? 0 : 3, 'status' => 'active', 'approval_status' => 'approved',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (['sku_masters', 'inventory_items'] as $table) {
            foreach ([['NO.', 'PCS'], ['NOS.', 'PCS'], ['QUANTITY', 'PCS'], ['OTHERS', 'OTH'], ['KGS', 'KG'], ['L', 'LTR']] as [$from, $to]) {
                DB::table($table)->where('unit', $from)->update(['unit' => $to]);
            }
        }
    }

    public function down(): void
    {
        // Kept on rollback: some of these codes may already have existed (e.g. PCS on live).
    }
};
