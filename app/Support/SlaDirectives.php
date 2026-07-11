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

    public static function label(?string $value): string
    {
        return self::OPTIONS[$value] ?? 'Not set';
    }
}
