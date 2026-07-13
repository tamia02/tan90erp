<?php

namespace App\Support;

// Fixed SLA tiers every role picks from in their profile — kept as one
// shared list so Settings (self-service) and admin Users management never
// drift out of sync with each other.
class SlaDirectives
{
    public const OPTIONS = [
        'standard_24h' => 'Standard (24h)',
        'priority_12h' => 'Priority (12h)',
        'critical_4h' => 'Critical (4h)',
    ];

    private const HOURS = [
        'standard_24h' => 24,
        'priority_12h' => 12,
        'critical_4h' => 4,
    ];

    public static function label(?string $value): string
    {
        return self::OPTIONS[$value] ?? 'Not set';
    }

    /** Hours until SLA breach for a directive tier, falling back to $default when unset/unrecognized. */
    public static function hours(?string $value, int $default = 12): int
    {
        return self::HOURS[$value] ?? $default;
    }
}
