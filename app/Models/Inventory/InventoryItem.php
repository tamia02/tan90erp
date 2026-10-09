<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    public const TYPES = [
        'rm' => 'Raw Material (RM)',
        'pm' => 'Packaging Material (PM)',
        'fg' => 'Finished Good (FG)',
        'trading' => 'Trading Good',
    ];

    // Only these can be sold / dispatched; RM and PM are consumed in production.
    public const SELLABLE_TYPES = ['fg', 'trading'];

    protected $fillable = ['sku', 'code', 'name', 'item_type', 'unit', 'opening_qty', 'active', 'custom_fields'];

    protected function casts(): array
    {
        return ['opening_qty' => 'decimal:3', 'active' => 'boolean', 'custom_fields' => 'array'];
    }

    public function bomLines(): HasMany
    {
        return $this->hasMany(InventoryBomLine::class, 'fg_item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'item_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->item_type] ?? $this->item_type;
    }

    public const SKU_NAME_PERMISSION = 'inventory.chemical_names.view';

    // SKU Name (sku) is the real chemical name and is masked: people without
    // this permission see the Display Name (name) and Code only.
    public static function canSeeSkuName(?\App\Models\User $user): bool
    {
        return \App\Support\GrantedPermission::has($user, self::SKU_NAME_PERMISSION);
    }

    /**
     * Items picked by what people type: SKU Name, Code or Display Name (masked
     * users only ever see the Display Name / Code), case-insensitive.
     *
     * @param  array<int, string>  $labels
     * @return \Illuminate\Support\Collection<string, self>  keyed by lower-case label
     */
    public static function matchLabels(array $labels): \Illuminate\Support\Collection
    {
        $labels = array_values(array_unique(array_filter(array_map(fn ($l) => trim((string) $l), $labels))));
        if (! $labels) {
            return collect();
        }
        $items = static::whereIn('sku', $labels)->orWhereIn('code', $labels)->orWhereIn('name', $labels)->get();

        return collect($labels)->mapWithKeys(function ($label) use ($items) {
            $l = mb_strtolower($label);
            $item = $items->first(fn ($i) => mb_strtolower($i->sku) === $l)
                ?? $items->first(fn ($i) => $i->code && mb_strtolower($i->code) === $l)
                ?? $items->first(fn ($i) => $i->name && mb_strtolower($i->name) === $l);

            return $item ? [$l => $item] : [];
        });
    }

    public function displayName(): string
    {
        return $this->name ?: $this->sku;
    }
}
