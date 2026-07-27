<?php

use App\Models\Tan90\BomRecipeCosting\Component;
use App\Models\Tan90\MasterData\Item;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Client does not want chemical/raw-material identities exposed anywhere in
// the app - not even via a "clever" abbreviation. The existing
// tan90_components.code values (e.g. "CMP-MGNO3" for Magnesium Nitrate) and
// tan90_items.masking_code values (e.g. "MGN") both leak the chemical via
// its formula/abbreviation, so this regenerates both as plain sequential
// codes (RM-0001, PKG-0001, ...) that carry no information about the
// underlying material, and redacts the leak that already made it into
// existing audit log text.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tan90_components', function (Blueprint $table) {
            if (! Schema::hasColumn('tan90_components', 'masking_code')) {
                $table->string('masking_code')->nullable()->unique()->after('code');
            }
        });

        $componentPrefixes = [
            'Raw Material' => 'RM',
            'Packaging' => 'PKG',
            'Intermediate' => 'INT',
            'Consumable' => 'CON',
        ];
        $componentSequence = [];

        Component::orderBy('id')->get()->each(function (Component $component) use ($componentPrefixes, &$componentSequence) {
            $prefix = $componentPrefixes[$component->type] ?? 'MD';
            $componentSequence[$prefix] = ($componentSequence[$prefix] ?? 0) + 1;
            $newCode = sprintf('%s-%04d', $prefix, $componentSequence[$prefix]);

            $this->redactAuditTrail('tan90_audit_logs', 'description', Component::class, $component->id, array_filter([$component->name, $component->code]), $newCode);

            $component->forceFill(['masking_code' => $newCode])->saveQuietly();
        });

        $itemPrefixes = [
            'Chemicals' => 'RM',
            'Packaging Materials - Panels' => 'PKG',
            'Packaging Materials - Pouch & Roll' => 'PKG',
            'Finished Goods' => 'FG',
        ];
        $itemSequence = [];

        Item::with('category')->orderBy('id')->get()->each(function (Item $item) use ($itemPrefixes, &$itemSequence) {
            $prefix = $itemPrefixes[$item->category?->name] ?? 'MD';
            $itemSequence[$prefix] = ($itemSequence[$prefix] ?? 0) + 1;
            $newCode = sprintf('%s-%04d', $prefix, $itemSequence[$prefix]);

            $this->redactAuditTrail('tan90_master_audit_logs', 'summary', Item::class, $item->id, array_filter([$item->name, $item->masking_code]), $newCode);
            $this->redactAuditTrail('tan90_master_audit_logs', 'record_label', Item::class, $item->id, array_filter([$item->name, $item->masking_code]), $newCode);

            $item->forceFill(['masking_code' => $newCode])->saveQuietly();
        });
    }

    /**
     * Existing audit rows already baked the leaking name/code into free
     * text (e.g. "Created CMP-MGNO3 Magnesium Nitrate.") - replace those
     * substrings with the new opaque code so history doesn't keep leaking
     * what the ongoing UI now hides.
     */
    private function redactAuditTrail(string $table, string $column, string $subjectClass, int $subjectId, array $leakedValues, string $newCode): void
    {
        if (! Schema::hasTable($table) || ! $leakedValues) {
            return;
        }

        $idColumn = $table === 'tan90_audit_logs' ? 'auditable_id' : 'entity_id';
        $typeColumn = $table === 'tan90_audit_logs' ? 'auditable_type' : 'entity_type';

        DB::table($table)
            ->where($typeColumn, $subjectClass)
            ->where($idColumn, $subjectId)
            ->whereNotNull($column)
            ->get()
            ->each(function ($row) use ($table, $column, $leakedValues, $newCode) {
                $text = $row->{$column};
                foreach ($leakedValues as $leaked) {
                    $text = str_replace((string) $leaked, $newCode, $text);
                }

                // Old text often named both the code and the name back to
                // back (e.g. "CMP-MGNO3 Magnesium Nitrate"), which collapses
                // to the same replacement twice ("RM-0001 RM-0001").
                $text = preg_replace('/\b('.preg_quote($newCode, '/').')(\s+\1\b)+/', '$1', $text);

                if ($text !== $row->{$column}) {
                    DB::table($table)->where('id', $row->id)->update([$column => $text]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('tan90_components', function (Blueprint $table) {
            $table->dropColumn('masking_code');
        });
    }
};
