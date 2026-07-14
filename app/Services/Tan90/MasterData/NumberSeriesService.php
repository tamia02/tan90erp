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

            $number = $series->next_number;
            $code = $this->render($series->pattern, $number, $context);

            $series->next_number = $number + 1;
            $series->preview = $this->render($series->pattern, $number + 1, $context);
            $series->saveQuietly(); // internal counter bump, not a user-facing audit-worthy edit

            return $code;
        });
    }

    private function render(string $pattern, int $number, array $context): string
    {
        $replacements = array_merge($context, [
            'YYYY' => now()->format('Y'),
            'YYYYMM' => now()->format('Ym'),
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
