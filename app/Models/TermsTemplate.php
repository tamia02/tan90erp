<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Terms & Conditions + Notes for a vendor / customer category (or a default,
// or assigned to one particular vendor / customer).
class TermsTemplate extends Model
{
    public const PARTIES = ['vendor' => 'Vendors (Purchase Orders)', 'customer' => 'Customers (Sales / Outward)'];

    // Categories as in the client's Zoho: PO "Purchase Nature", SO "Business Vertical".
    public const CATEGORY_FIELD = ['vendor' => 'Purchase Nature', 'customer' => 'Business Vertical'];

    protected $fillable = ['party_type', 'category', 'name', 'terms', 'notes', 'active', 'source', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $t) {
            if (auth()->check()) {
                $t->created_by ??= auth()->id();
                $t->updated_by = auth()->id();
            }
        });
        static::updating(function (self $t) {
            if (auth()->check()) {
                $t->updated_by = auth()->id();
            }
        });
    }

    /** Category choices for a side: the dropdown values from Zoho, plus any already used. */
    public static function categories(string $party): array
    {
        $entity = $party === 'vendor' ? 'purchaseorder' : 'salesorder';
        $fromZoho = (array) (ZohoFieldDefinition::where('entity', $entity)->where('label', self::CATEGORY_FIELD[$party])->value('options') ?? []);
        $names = array_map(fn ($o) => is_array($o) ? ($o['name'] ?? $o['value'] ?? '') : (string) $o, $fromZoho);

        return collect($names)->merge(static::where('party_type', $party)->whereNotNull('category')->pluck('category'))
            ->filter()->unique()->sort()->values()->all();
    }

    /**
     * Which template applies: the party's own > its category's > the side's default.
     */
    public static function resolve(string $party, ?int $partyTemplateId, ?string $category): ?self
    {
        if ($partyTemplateId && ($own = static::where('active', true)->find($partyTemplateId))) {
            return $own;
        }
        if ($category && ($byCategory = static::where('active', true)->where('party_type', $party)->where('category', $category)->latest('id')->first())) {
            return $byCategory;
        }

        // Default: no category and not one made for particular vendors / customers.
        return static::where('active', true)->where('party_type', $party)->whereNull('category')
            ->whereNotIn('id', static::assignedIds($party))->latest('id')->first();
    }

    /** Templates in use by particular vendors / customers. */
    public static function assignedIds(string $party): array
    {
        $query = $party === 'vendor' ? VendorMaster::query() : \App\Models\Tan90\MasterData\Customer::query();

        return $query->whereNotNull('terms_template_id')->distinct()->pluck('terms_template_id')->all();
    }
}
