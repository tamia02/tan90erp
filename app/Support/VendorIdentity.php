<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use App\Models\VendorMaster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

/** Resolves the company identity behind a vendor portal login. */
final class VendorIdentity
{
    /** @var array<int, Collection<string, string>> */
    private static array $resolvedNames = [];

    public static function names(?User $user = null): Collection
    {
        $user ??= auth()->user();
        if (! $user || $user->role !== Role::Vendor) {
            return collect();
        }

        if (isset(self::$resolvedNames[$user->id])) {
            return self::$resolvedNames[$user->id];
        }

        $candidates = collect([
            $user->name,
            preg_replace('/\s+(?:vendor|supplier)(?:\s+(?:user|executive|manager))?$/i', '', (string) $user->name),
            $user->vendor_name ?? null,
        ])->filter(fn ($value) => filled($value))->map(fn ($value) => self::normalise($value))->unique();

        // File/database cache must contain plain arrays, not an Eloquent
        // Collection. Serialized model collections can be unserialized before
        // the model autoloader is ready on a subsequent request.
        $vendors = Cache::remember('tan90:vendor-master:identity-candidates', now()->addMinutes(5), fn () =>
            VendorMaster::query()->get(['vendor_name', 'contact_email'])
                ->map(fn (VendorMaster $vendor): array => [
                    'vendor_name' => $vendor->vendor_name,
                    'contact_email' => $vendor->contact_email,
                ])->all()
        );

        return self::$resolvedNames[$user->id] = collect($vendors)
            ->filter(function (array $vendor) use ($candidates, $user): bool {
                return $candidates->contains(self::normalise($vendor['vendor_name'] ?? ''))
                    || (filled($user->email) && filled($vendor['contact_email'] ?? null)
                        && strcasecmp(trim($user->email), trim((string) $vendor['contact_email'])) === 0);
            })
            ->pluck('vendor_name')->filter()->unique()->values();
    }

    /** Apply the vendor ownership boundary to any vendor_name query. */
    public static function apply(Builder $query, ?User $user = null, string $column = 'vendor_name'): Builder
    {
        $user ??= auth()->user();
        $names = self::names($user);
        if ($names->isNotEmpty()) {
            return $query->whereIn($column, $names->all());
        }
        // These are internal shared vendor-operations demo accounts. They
        // intentionally work the released queue until an account is linked to
        // one VendorMaster record; unknown external accounts see nothing.
        if (self::isShared($user)) {
            return $query;
        }
        return $query->whereRaw('1 = 0');
    }

    public static function isShared(?User $user = null): bool
    {
        $user ??= auth()->user();
        return $user?->role === Role::Vendor
            && self::names($user)->isEmpty()
            && str_ends_with((string) $user->email, '@tan90.demo');
    }

    private static function normalise(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $value))) ?: '';
    }
}
