<?php

use App\Models\Tan90\MasterData\Item;
use Illuminate\Database\Migrations\Migration;

// 2026_07_27_000001_add_masking_code_to_components_and_regenerate_item_codes
// generated masking codes once, for every item that existed at the time.
// Every item created since — any source: Master Data UI, BOM, or (as
// confirmed live) all 185 real items pulled in by today's Zoho sync — never
// got one, silently defeating the client's "chemical identity never appears
// in the app" requirement. Item::booted() now generates one for every new
// item going forward; this is the one-time catch-up for what already exists.
return new class extends Migration
{
    public function up(): void
    {
        Item::withTrashed()
            ->whereNull('masking_code')
            ->orderBy('id')
            ->each(function (Item $item) {
                $item->forceFill([
                    'masking_code' => Item::nextMaskingCode($item->tan90_item_category_id),
                ])->saveQuietly();
            });
    }

    public function down(): void
    {
        // Not reversible — no record of which codes were pre-existing vs backfilled.
    }
};
