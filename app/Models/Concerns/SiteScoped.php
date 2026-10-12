<?php

namespace App\Models\Concerns;

use App\Support\SiteLocation;
use Illuminate\Database\Eloquent\Builder;

// Rows belong to a site (location_id). A person tied to a site sees that site's
// rows plus rows with no site; Super Admin and people with no site see all.
// Numbering and duplicate checks that must look across every site use
// Model::withoutSiteScope().
trait SiteScoped
{
    public static function bootSiteScoped(): void
    {
        static::addGlobalScope('site', function (Builder $query) {
            SiteLocation::scope($query, $query->getModel()->qualifyColumn(static::siteColumn()));
        });
    }

    public static function siteColumn(): string
    {
        return 'location_id';
    }

    public static function withoutSiteScope(): Builder
    {
        return static::withoutGlobalScope('site');
    }
}
