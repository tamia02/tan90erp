<?php

namespace App\Enums;

// Seven roles, kept fully separate per the client — nothing here merges.
// Mirrors the React prototype's roleMeta (src/lib/auth.ts) so screens and
// business logic translate 1:1.
enum Role: string
{
    case Guard = 'guard';
    case Vendor = 'vendor';
    case StoreExec = 'storeExec';
    case Qc = 'qc';
    case StoreManager = 'storeManager';
    case Finance = 'finance';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Guard => 'Security Guard',
            self::Vendor => 'Vendor User',
            self::StoreExec => 'Store Executive',
            self::Qc => 'QC User',
            self::StoreManager => 'Store Manager',
            self::Finance => 'Finance User',
            self::Admin => 'Administrator',
        };
    }

    public function moduleName(): string
    {
        return match ($this) {
            self::Guard => 'Guard Gate Module',
            self::Vendor => 'Vendor Submission Module',
            self::StoreExec => 'Unloading Desk Module',
            self::Qc => 'QC Check Module',
            self::StoreManager => 'GRN Check & Stock Module',
            self::Finance => 'Finance Review Module',
            self::Admin => 'Admin Control Module',
        };
    }

    public function homeRouteName(): string
    {
        return match ($this) {
            self::Guard => 'guard.dashboard',
            self::Vendor => 'vendor.dashboard',
            self::StoreExec => 'unloading.dashboard',
            self::Qc => 'qc.dashboard',
            self::StoreManager => 'grn.dashboard',
            self::Finance => 'finance.dashboard',
            self::Admin => 'admin.dashboard',
        };
    }

    /** @return array<string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
