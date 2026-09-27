<?php

namespace App\Models\Tan90\MasterData;

use App\Models\Tan90\MasterData\Concerns\IsMasterRecord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    use HasFactory;
    use IsMasterRecord;

    protected $table = 'tan90_items';

    protected $fillable = [
        'sku', 'code', 'name', 'tan90_item_category_id', 'tan90_uom_id', 'hsn',
        'masking_code', 'qc_required', 'batch_tracking', 'temperature', 'storage',
        'reorder_level', 'standard_cost', 'status', 'approval_status',
    ];

    /**
     * 2026_07_27_000001_add_masking_code_to_components_and_regenerate_item_codes
     * generated these once for every item that existed at the time — but
     * only once. Every item created since (any source: Master Data UI, BOM,
     * or the Zoho sync — confirmed live, all 185 real Zoho-synced items had
     * masking_code=null) never got one, silently defeating the client's
     * explicit "chemical identity must never appear in the app" requirement
     * for anything created after that migration ran. This closes that gap
     * at the model level so it's guaranteed for every item going forward,
     * regardless of how it's created.
     */
    protected static function booted(): void
    {
        static::creating(function (self $item) {
            if (empty($item->masking_code)) {
                $item->masking_code = static::nextMaskingCode($item->tan90_item_category_id);
            }
        });
    }

    /** Same category-prefix scheme the original migration used — kept in sync deliberately. */
    private const MASKING_PREFIXES = [
        'Chemicals' => 'RM',
        'Packaging Materials - Panels' => 'PKG',
        'Packaging Materials - Pouch & Roll' => 'PKG',
        'Finished Goods' => 'FG',
    ];

    public static function nextMaskingCode(?int $categoryId): string
    {
        $categoryName = $categoryId ? ItemCategory::find($categoryId)?->name : null;
        $prefix = self::MASKING_PREFIXES[$categoryName] ?? 'MD';

        $maxSequence = static::withTrashed()
            ->where('masking_code', 'like', "{$prefix}-%")
            ->pluck('masking_code')
            ->map(fn ($code) => (int) substr($code, strlen($prefix) + 1))
            ->max() ?? 0;

        return sprintf('%s-%04d', $prefix, $maxSequence + 1);
    }

    public static function criticalFields(): array
    {
        return ['tan90_uom_id', 'hsn', 'standard_cost'];
    }

    // The real chemical name must never appear in audit text - only the
    // opaque masking_code identifies this record everywhere.
    public function auditLabel(): string
    {
        return $this->getAttribute('masking_code')
            ?: class_basename($this).' #'.$this->getKey();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'tan90_item_category_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'tan90_uom_id');
    }
}
