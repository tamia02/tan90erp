<?php

namespace App\Models\Concerns;

use App\Support\SiteLocation;
use Illuminate\Database\Eloquent\Builder;

// Rows that belong to a site through their parent: GRN / QC / unloading /
// validation rows through their gate entry, vendor submissions through their
// PO. The parent's own site scope decides; no site on the person = no filter.
trait SiteScopedViaParent
{
    public static function bootSiteScopedViaParent(): void
    {
        static::addGlobalScope('site', function (Builder $query) {
            if (SiteLocation::current() === null) {
                return;
            }
            [$column, $parentClass, $parentKey] = static::siteParent();
            $query->whereIn($query->getModel()->qualifyColumn($column), $parentClass::query()->select($parentKey));
        });
    }

    /** @return array{0: string, 1: class-string, 2: string} [own column, parent model, parent column] */
    public static function siteParent(): array
    {
        return ['gate_entry_id', \App\Models\GateEntry::class, 'id'];
    }

    public static function withoutSiteScope(): Builder
    {
        return static::withoutGlobalScope('site');
    }
}
