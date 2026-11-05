<?php

namespace App\Services\Tan90\MasterData;

use App\Models\Tan90\MasterData\NumberSeries;
use Illuminate\Support\Facades\DB;

/**
 * Generates the next code for a module from its configured pattern
 * (LE-{YYYY}-{####}, {CATEGORY}-{####}, ...). Wired into
 * StoreMasterDataRequest::prepareForValidation() to auto-fill a blank
 * `code`/natural-key field on create when a matching tan90_number_series row
 * exists for the entity's title.
 */
class NumberSeriesService
{
    /**
     * @param  array<string, string>  $context  extra tokens like ['CATEGORY' => 'CHEM', 'STATE' => 'MH']
     */
    public function next(string $module, array $context = []): ?string
    {
        return DB::transaction(function () use ($module, $context) {
            $series = NumberSeries::where('module', $module)->where('status', 'active')->lockForUpdate()->first();

            if (! $series) {
                return null;
            }

            // Yearly = Indian financial year (April-March), so PO numbers restart
            // at 1 every April. A series that never tracked a period just starts
            // tracking now, without restarting its count.
            $period = $this->period($series->reset_policy);
            if ($period !== null && $series->current_period !== $period) {
                if ($series->current_period !== null) {
                    $series->next_number = 1;
                }
                $series->current_period = $period;
            }

            $number = $series->next_number;
            $code = $this->render($series->pattern, $number, $context);

            $series->next_number = $number + 1;
            $series->preview = $this->render($series->pattern, $number + 1, $context);
            $series->saveQuietly(); // internal counter bump, not a user-facing audit-worthy edit

            return $code;
        });
    }

    /** What the next code will look like, without using it up. */
    public function peek(string $module, array $context = []): ?string
    {
        $series = NumberSeries::where('module', $module)->where('status', 'active')->first();
        if (! $series) {
            return null;
        }
        $period = $this->period($series->reset_policy);
        $number = ($period !== null && $series->current_period !== null && $series->current_period !== $period) ? 1 : $series->next_number;

        return $this->render($series->pattern, $number, $context);
    }

    // 2026-10 -> "2627": financial year April 2026 - March 2027.
    public static function financialYear(?\DateTimeInterface $date = null): string
    {
        $date = \Illuminate\Support\Carbon::instance($date ?? now());
        $start = $date->month >= 4 ? $date->year : $date->year - 1;

        return substr((string) $start, -2).substr((string) ($start + 1), -2);
    }

    private function period(string $policy): ?string
    {
        return match ($policy) {
            'Yearly' => self::financialYear(),
            'Monthly' => now()->format('Ym'),
            'Daily' => now()->format('Ymd'),
            default => null,
        };
    }

    private function render(string $pattern, int $number, array $context): string
    {
        $replacements = array_merge($context, [
            'YYYY' => now()->format('Y'),
            'YYYYMM' => now()->format('Ym'),
            'FY' => self::financialYear(),
        ]);

        $result = preg_replace_callback('/\{([A-Z]+)\}/', function ($matches) use ($replacements) {
            return $replacements[$matches[1]] ?? $matches[0];
        }, $pattern);

        // Numeric run-length tokens: {####} -> zero-padded sequence number.
        return preg_replace_callback('/\{(#+)\}/', function ($matches) use ($number) {
            return str_pad((string) $number, strlen($matches[1]), '0', STR_PAD_LEFT);
        }, $result);
    }
}
