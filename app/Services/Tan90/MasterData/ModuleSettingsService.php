<?php

namespace App\Services\Tan90\MasterData;

use App\Models\Tan90\MasterData\ModuleSetting;
use App\Models\User;

/**
 * GST / Maps / SMTP / API credentials are encrypted at rest with Laravel's
 * Crypt facade (see ModuleSetting::put/plainValue) and are never returned to
 * the view in clear text - only a masked placeholder. This mirrors the
 * demo's Settings screen tabs (general / masterPolicy / gst / maps / email /
 * security) but with real server-side encryption instead of localStorage.
 */
class ModuleSettingsService
{
    public const SCHEMA = [
        'general' => [
            ['key' => 'companyName', 'label' => 'Company Name', 'type' => 'text'],
            ['key' => 'dateFormat', 'label' => 'Date Format', 'type' => 'select', 'options' => ['DD MMM YYYY', 'DD/MM/YYYY', 'YYYY-MM-DD']],
            ['key' => 'timezone', 'label' => 'Timezone', 'type' => 'select', 'options' => ['Asia/Kolkata', 'UTC']],
            ['key' => 'currency', 'label' => 'Currency', 'type' => 'select', 'options' => ['INR', 'USD', 'EUR']],
            ['key' => 'language', 'label' => 'Language', 'type' => 'select', 'options' => ['English', 'Hindi']],
        ],
        'masterPolicy' => [
            ['key' => 'approvalRequired', 'label' => 'Approval Required', 'type' => 'checkbox'],
            ['key' => 'softDelete', 'label' => 'Soft Delete', 'type' => 'checkbox'],
            ['key' => 'makerChecker', 'label' => 'Maker-Checker', 'type' => 'checkbox'],
            ['key' => 'effectiveDating', 'label' => 'Effective Dating', 'type' => 'checkbox'],
            ['key' => 'duplicateThreshold', 'label' => 'Duplicate Similarity Threshold %', 'type' => 'number'],
        ],
        'gst' => [
            ['key' => 'enabled', 'label' => 'Enable GST Verification', 'type' => 'checkbox'],
            ['key' => 'endpointTemplate', 'label' => 'Endpoint Template', 'type' => 'text'],
            ['key' => 'apiKey', 'label' => 'GST API Key', 'type' => 'secret'],
            ['key' => 'timeout', 'label' => 'Timeout Seconds', 'type' => 'number'],
            ['key' => 'cacheHours', 'label' => 'Success Cache Hours', 'type' => 'number'],
        ],
        'maps' => [
            ['key' => 'enabled', 'label' => 'Enable Google Maps', 'type' => 'checkbox'],
            ['key' => 'browserKey', 'label' => 'Browser API Key', 'type' => 'secret'],
            ['key' => 'serverKey', 'label' => 'Server API Key', 'type' => 'secret'],
            ['key' => 'autocomplete', 'label' => 'Address Autocomplete', 'type' => 'checkbox'],
        ],
        'email' => [
            ['key' => 'mailer', 'label' => 'Mailer', 'type' => 'select', 'options' => ['smtp', 'log']],
            ['key' => 'host', 'label' => 'SMTP Host', 'type' => 'text'],
            ['key' => 'port', 'label' => 'SMTP Port', 'type' => 'number'],
            ['key' => 'username', 'label' => 'SMTP Username', 'type' => 'text'],
            ['key' => 'password', 'label' => 'SMTP Password', 'type' => 'secret'],
            ['key' => 'encryption', 'label' => 'Encryption', 'type' => 'select', 'options' => ['ssl', 'tls', 'none']],
            ['key' => 'fromEmail', 'label' => 'From Email', 'type' => 'email'],
            ['key' => 'fromName', 'label' => 'From Name', 'type' => 'text'],
        ],
        'security' => [
            ['key' => 'sessionTimeout', 'label' => 'Session Timeout Minutes', 'type' => 'number'],
            ['key' => 'mfaRequired', 'label' => 'MFA Required', 'type' => 'checkbox'],
            ['key' => 'passwordDays', 'label' => 'Password Expiry Days', 'type' => 'number'],
            ['key' => 'auditRetention', 'label' => 'Audit Retention', 'type' => 'select', 'options' => ['3 Years', '5 Years', '8 Years', 'Permanent']],
        ],
    ];

    public function groupValues(string $group): array
    {
        $rows = ModuleSetting::where('group', $group)->get()->keyBy('key');
        $values = [];

        foreach (self::SCHEMA[$group] ?? [] as $field) {
            $row = $rows->get($field['key']);
            if (! $row) {
                $values[$field['key']] = null;
                continue;
            }
            // Secrets are masked, never echoed back in clear text.
            $values[$field['key']] = $field['type'] === 'secret' && $row->value
                ? str_repeat('•', 12)
                : $row->plainValue();
        }

        return $values;
    }

    public function save(string $group, array $input, ?User $actor): void
    {
        foreach (self::SCHEMA[$group] ?? [] as $field) {
            $key = $field['key'];
            $isSecret = $field['type'] === 'secret';

            if ($isSecret && ! array_key_exists($key, $input)) {
                continue; // blank secret field on submit means "keep current value"
            }

            $value = $field['type'] === 'checkbox'
                ? (isset($input[$key]) && $input[$key] ? '1' : '0')
                : ($input[$key] ?? null);

            ModuleSetting::put($group, $key, $value === '' ? null : $value, $isSecret, $actor?->id);
        }
    }

    /** Real (unmasked) value - only for server-side use (e.g. calling an API), never for display. */
    public function rawValue(string $group, string $key): ?string
    {
        return ModuleSetting::where('group', $group)->where('key', $key)->first()?->plainValue();
    }
}
