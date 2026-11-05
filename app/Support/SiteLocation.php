<?php

namespace App\Support;

use App\Models\Tan90\MasterData\Location;
use App\Models\Tan90\MasterData\Warehouse;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

// Location-based access: which site (Master Data > Locations) a person works at
// and which site a record belongs to. A person with no site, and Super Admin,
// see every site - exactly as before this existed.
class SiteLocation
{
    // Extra words that name a site in the Guard's location list, Zoho warehouse
    // names and PO delivery addresses (e.g. "Bhiwandi WH (MU)", "Tan90 Mumbai").
    private const ALIASES = [
        'LOC-BHW' => ['bhiwandi', 'mumbai', 'maharashtra'],
        'LOC-CHN' => ['chennai', 'tamil nadu'],
        'LOC-DEL' => ['delhi', 'pali', 'faridabad', 'haryana'],
    ];

    /** The site a signed-in person is limited to, or null for "all sites". */
    public static function forUser(?User $user): ?int
    {
        if (! $user || $user->isAdmin()) {
            return null;
        }

        $profile = $user->tan90Profile()->with(['assignedPlant', 'assignedLocation'])->first();
        if ($profile) {
            if ($profile->all_locations) {
                return null;
            }
            if ($profile->assigned_location_id) {
                return (int) $profile->assigned_location_id;
            }
            if ($profile->assignedPlant?->tan90_location_id) {
                return (int) $profile->assignedPlant->tan90_location_id;
            }
        }

        return $user->location_id ? (int) $user->location_id : null;
    }

    public static function current(): ?int
    {
        return self::forUser(auth()->user());
    }

    /** A site-scoped person cannot see or mutate a record without a confirmed site assignment. */
    public static function canAccessSiteRecord(?User $user, ?int $recordSiteId): bool
    {
        $site = self::forUser($user);

        return $site === null || ($recordSiteId !== null && $recordSiteId === $site);
    }

    public static function assertCanAccessSiteRecord(?User $user, ?int $recordSiteId): void
    {
        abort_unless(self::canAccessSiteRecord($user, $recordSiteId), 403);
    }

    /** Locations visible to this user; a scoped profile or account sees only its assigned site. */
    public static function visibleLocations(): Collection
    {
        $query = Location::query()->whereIn('type', ['Manufacturing', 'Warehouse'])->orderBy('name');
        $site = self::current();

        if ($site !== null) {
            $query->whereKey($site);
        }

        return $query->get(['id', 'code', 'name', 'city']);
    }

    public static function nameForId(?int $id): ?string
    {
        return $id ? Location::whereKey($id)->value('name') : null;
    }

    /** Location aliases used to classify older gate entries that predate location_id. */
    public static function nameTokens(int $id): array
    {
        $location = Location::find($id);
        if (! $location) {
            return [];
        }

        return collect([$location->name, $location->code, $location->city, ...(self::ALIASES[$location->code] ?? [])])
            ->map(fn ($value) => Str::lower(trim((string) $value)))
            ->filter(fn ($value) => mb_strlen($value) >= 4)
            ->reject(fn ($value) => in_array($value, ['maharashtra', 'tamil nadu', 'haryana'], true))
            ->unique()->values()->all();
    }

    public static function matchesName(?string $name, int $id): bool
    {
        $haystack = Str::lower(trim((string) $name));

        return $haystack !== '' && collect(self::nameTokens($id))->contains(fn ($token) => str_contains($haystack, $token));
    }

    /** The main site (Bhiwandi): stock changes by all-site users and older stock rows land here. */
    public static function defaultId(): ?int
    {
        static $id = false;
        if ($id === false || app()->runningUnitTests()) {
            $id = Location::where('code', 'LOC-BHW')->value('id') ?? Location::orderBy('id')->value('id');
        }

        return $id ? (int) $id : null;
    }

    /** Site id from any name that mentions it (warehouse, location, Guard list name). */
    public static function idForName(?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        if ($warehouse = Warehouse::where('name', $name)->first()) {
            return $warehouse->tan90_location_id ? (int) $warehouse->tan90_location_id : null;
        }
        $haystack = Str::lower($name);
        foreach (Location::all() as $location) {
            $words = array_filter([
                Str::lower((string) $location->name), Str::lower((string) $location->code), Str::lower((string) $location->city),
                ...(self::ALIASES[$location->code] ?? []),
            ], fn ($w) => strlen($w) >= 4);
            foreach ($words as $word) {
                if (str_contains($haystack, $word)) {
                    return (int) $location->id;
                }
            }
        }

        return null;
    }

    /** Limit a query to the current person's site; rows with no site stay visible. */
    public static function scope(Builder $query, string $column = 'location_id'): Builder
    {
        $site = self::current();

        return $site === null ? $query : $query->where(fn ($q) => $q->where($column, $site)->orWhereNull($column));
    }
}
