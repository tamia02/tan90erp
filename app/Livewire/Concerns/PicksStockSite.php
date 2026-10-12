<?php

namespace App\Livewire\Concerns;

use App\Models\Tan90\MasterData\Location;
use App\Services\Inventory\InventoryService;
use App\Support\SiteLocation;

// Inventory screens: a person tied to a site always works on that site's stock.
// Super Admin / people with no site pick one in a "Site" dropdown ('' = all
// sites added up, the same numbers as before stock had a site).
trait PicksStockSite
{
    public string $stockSite = '';

    // Screens that change stock (Create Bundle) start on the main site instead of "all".
    protected function stockSiteRequired(): bool
    {
        return false;
    }

    public function mountPicksStockSite(): void
    {
        if ($this->stockSiteRequired() && $this->canPickStockSite() && $this->stockSite === '') {
            $this->stockSite = (string) SiteLocation::defaultId();
        }
    }

    public function canPickStockSite(): bool
    {
        return SiteLocation::current() === null;
    }

    protected function inventory(): InventoryService
    {
        $inventory = app(InventoryService::class);

        return $this->canPickStockSite() && $this->stockSite !== '' ? $inventory->atSite((int) $this->stockSite) : $inventory;
    }

    /** @return array{sites: \Illuminate\Support\Collection, canPick: bool, current: ?string} */
    protected function stockSiteView(): array
    {
        $own = SiteLocation::current();

        return [
            'stockSites' => $this->canPickStockSite() ? Location::orderBy('name')->get(['id', 'name']) : collect(),
            'stockSiteName' => $own ? Location::find($own)?->name : ($this->stockSite !== '' ? Location::find((int) $this->stockSite)?->name : null),
        ];
    }
}
