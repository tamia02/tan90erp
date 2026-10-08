<?php

namespace App\Services\Zoho;

use App\Models\Inventory\InventoryItem;
use App\Models\SkuMaster;
use RuntimeException;

// Zoho is the source of truth for Items. This pulls EVERY Zoho item (active and
// inactive) into SKU Master and the Inventory module. It only ever sends GET
// requests to Zoho, and saves SKU Master rows without model events so the
// existing "push SKU to Zoho" observer can't echo them straight back.
//
// The ERP item name (sku_masters.sku) is the Zoho item NAME, because Zoho PO
// lines - and therefore gate entries and GRN stock - carry the name. Zoho's
// own SKU code goes to product_code; zoho_item_id keeps the link stable.
class ZohoItemPullService
{
    public const TYPE_LABELS = [
        'fg' => 'Zoho — Composite Item (FG)',
        'pm' => 'Zoho — Packaging Material (PM)',
        'rm' => 'Zoho — Raw Material (RM)',
        'trading' => 'Zoho — Trading Good',
    ];

    public function __construct(private ZohoReadClient $zoho) {}

    public function configured(): bool
    {
        return $this->zoho->configured();
    }

    /** @return array<string, int|array> */
    public function pull(bool $dryRun = false): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Zoho item pull is not configured (organization id / refresh token / client).');
        }

        $items = $this->zoho->all('/items', 'items');
        $nameCounts = collect($items)->countBy(fn ($i) => mb_strtolower(self::cleanName((string) $i['name'])));

        $stats = ['fetched' => count($items), 'created' => 0, 'updated' => 0, 'duplicate_names' => 0, 'by_type' => []];
        $now = now();

        foreach ($items as $item) {
            $type = self::classify($item);
            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;

            $name = self::cleanName((string) $item['name']);
            if (($nameCounts[mb_strtolower($name)] ?? 0) > 1) {
                // Same name used by more than one Zoho item: keep them apart.
                $name .= ' ['.(filled($item['sku'] ?? null) ? $item['sku'] : $item['item_id']).']';
                $stats['duplicate_names']++;
            }

            $values = [
                'sku' => $name,
                'product_code' => ($item['sku'] ?? '') ?: null,
                'category' => self::TYPE_LABELS[$type],
                'unit' => self::normaliseUnit($item['unit'] ?? ''),
                'active' => ($item['status'] ?? 'active') === 'active',
                'mapped' => true,
                'unit_price' => is_numeric($item['rate'] ?? null) ? $item['rate'] : null,
                'vendor_name' => ($item['vendor_name'] ?? '') ?: null,
                'description' => ($item['description'] ?? '') ?: null,
                'reorder_level' => is_numeric($item['reorder_level'] ?? null) ? (int) $item['reorder_level'] : null,
                'zoho_item_id' => (string) $item['item_id'],
                'zoho_item_type' => $item['item_type'] ?? null,
                'zoho_is_composite' => (bool) ($item['is_combo_product'] ?? false),
                'zoho_tags' => self::tagNames($item) ?: null,
                'zoho_hsn' => ($item['hsn_or_sac'] ?? '') ?: null,
                'zoho_synced_at' => $now,
            ];

            $sku = SkuMaster::where('zoho_item_id', $values['zoho_item_id'])->first()
                ?? SkuMaster::whereNull('zoho_item_id')->whereRaw('LOWER(sku) = ?', [mb_strtolower($name)])->first();

            if ($dryRun) {
                $stats[$sku ? 'updated' : 'created']++;

                continue;
            }

            $oldSku = $sku?->sku;
            $sku ??= new SkuMaster;
            $wasNew = ! $sku->exists;
            SkuMaster::withoutEvents(fn () => $sku->fill($values)->save());
            $stats[$wasNew ? 'created' : 'updated']++;

            $this->syncInventoryItem($oldSku, $values, $type);
        }

        $stats['api_calls'] = $this->zoho->calls;

        return $stats;
    }

    public static function classify(array $item): string
    {
        $tags = array_map('mb_strtolower', self::tagList($item));

        return match (true) {
            (bool) ($item['is_combo_product'] ?? false) => 'fg',
            in_array('pcm', $tags, true) => 'rm',
            (bool) array_intersect($tags, ['box', 'bag', 'panel', 'sachet']) => 'pm',
            ($item['item_type'] ?? '') === 'purchases' => 'rm',
            in_array($item['item_type'] ?? '', ['sales', 'sales_and_purchases'], true) => 'trading',
            default => 'rm',
        };
    }

    // Zoho names sometimes contain non-breaking or double spaces that MySQL
    // treats as equal to a normal space - normalise so duplicates are caught.
    public static function cleanName(string $name): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $name));
    }

    public static function normaliseUnit(string $unit): string
    {
        $u = mb_strtolower(trim($unit));

        return match (true) {
            $u === '' || in_array($u, ['pcs', 'pc', 'piece', 'pieces', 'numbers', 'number', 'nos', 'no', 'qty', 'units', 'unit'], true) => 'PCS',
            in_array($u, ['kg', 'kgs', 'kilogram', 'kilograms'], true) => 'KG',
            in_array($u, ['box', 'boxes'], true) => 'BOX',
            in_array($u, ['l', 'ltr', 'ltrs', 'litre', 'litres', 'liter', 'liters'], true) => 'LTR',
            default => mb_strtoupper(trim($unit)),
        };
    }

    // Keep the Inventory module in step: same name, Zoho type, archived when
    // inactive in Zoho. A rename in Zoho renames the inventory item too.
    private function syncInventoryItem(?string $oldSku, array $values, string $type): void
    {
        $inv = InventoryItem::whereRaw('LOWER(sku) = ?', [mb_strtolower($oldSku ?? $values['sku'])])->first()
            ?? InventoryItem::whereRaw('LOWER(sku) = ?', [mb_strtolower($values['sku'])])->first()
            ?? new InventoryItem(['opening_qty' => 0]);

        $inv->fill([
            'sku' => $values['sku'],
            'item_type' => $type,
            'unit' => $values['unit'],
            'active' => $values['active'],
        ])->save();
    }

    /** @return array<int, string> */
    private static function tagList(array $item): array
    {
        return collect($item['tags'] ?? [])->pluck('tag_option_name')->filter()
            ->reject(fn ($t) => in_array($t, ['Products', 'Chennai Product'], true))->values()->all();
    }

    private static function tagNames(array $item): string
    {
        return implode(', ', self::tagList($item));
    }
}
