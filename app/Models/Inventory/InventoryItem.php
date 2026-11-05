<?php

namespace App\Models\Inventory;

use App\Models\User;
use App\Support\GrantedPermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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

    protected $fillable = ['sku', 'code', 'name', 'masked_name', 'alternative_name', 'item_type', 'unit', 'opening_qty', 'active', 'custom_fields', 'created_by', 'updated_by'];

    // Who added / last changed the item (left empty for Zoho sync / system).
    protected static function booted(): void
    {
        static::creating(function (self $item) {
            if (auth()->check()) {
                $item->created_by ??= auth()->id();
                $item->updated_by = auth()->id();
            }
        });
        static::updating(function (self $item) {
            if (auth()->check()) {
                $item->updated_by = auth()->id();
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

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
    public static function canSeeSkuName(?User $user): bool
    {
        return GrantedPermission::has($user, self::SKU_NAME_PERMISSION);
    }

    /**
     * Items picked by what people type: SKU Name, Code or Display Name (masked
     * users only ever see the Display Name / Code), case-insensitive.
     *
     * @param  array<int, string>  $labels
     * @return Collection<string, self> keyed by lower-case label
     */
    public static function matchLabels(array $labels): Collection
    {
        $labels = array_values(array_unique(array_filter(array_map(fn ($l) => trim((string) $l), $labels))));
        if (! $labels) {
            return collect();
        }
        $items = static::whereIn('sku', $labels)->orWhereIn('code', $labels)->orWhereIn('name', $labels)
            ->orWhereIn('masked_name', $labels)->orWhereIn('alternative_name', $labels)->get();

        return collect($labels)->mapWithKeys(function ($label) use ($items) {
            $l = mb_strtolower($label);
            $item = $items->first(fn ($i) => mb_strtolower($i->sku) === $l)
                ?? $items->first(fn ($i) => $i->code && mb_strtolower($i->code) === $l)
                ?? $items->first(fn ($i) => $i->name && mb_strtolower($i->name) === $l)
                ?? $items->first(fn ($i) => $i->masked_name && mb_strtolower($i->masked_name) === $l)
                ?? $items->first(fn ($i) => $i->alternative_name && mb_strtolower($i->alternative_name) === $l);

            return $item ? [$l => $item] : [];
        });
    }

    public function displayName(): string
    {
        return $this->name ?: $this->sku;
    }

    /** User-facing name; never falls back to a masked chemical SKU. */
    public function safeDisplayName(): string
    {
        return trim((string) ($this->masked_name ?: $this->alternative_name ?: $this->name ?: $this->code ?: 'Unnamed item'));
    }

    public function visibleNameFor(?User $user): string
    {
        if (filled($this->masked_name) || filled($this->alternative_name)) {
            return $this->safeDisplayName();
        }

        return self::canSeeSkuName($user) ? $this->displayName() : $this->safeDisplayName();
    }

    /** Preferred label for PO, invoice, and other documents visible outside inventory admins. */
    public function documentName(): string
    {
        return trim((string) ($this->masked_name ?: $this->alternative_name ?: $this->name ?: $this->sku));
    }
}
