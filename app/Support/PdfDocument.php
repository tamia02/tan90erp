<?php

namespace App\Support;

use App\Models\Tan90\MasterData\Location;

// Shared bits of the Zoho-style documents (Bill, GRN, Delivery Challan,
// Transfer Order): who issues the document and the amount in words.
class PdfDocument
{
    /**
     * The company block in the header: the issuing location's address and
     * GSTIN when we know it (like Zoho), else the registered office.
     *
     * @return array{name: string, lines: array<int, string>, gstin: string}
     */
    public static function issuer(?Location $location = null): array
    {
        if ($location && ($location->address || $location->city)) {
            return ['name' => (string) config('company.name'), 'lines' => self::addressLines($location), 'gstin' => (string) ($location->gstin ?: config('company.gstin'))];
        }

        return ['name' => (string) config('company.name'), 'lines' => (array) config('company.address_lines', []), 'gstin' => (string) config('company.gstin')];
    }

    /** @return array<int, string> */
    public static function addressLines(?Location $location): array
    {
        if (! $location) {
            return [];
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', (string) $location->address) ?: [])));
        $place = trim(implode(' ', array_filter([$location->city, $location->state, $location->pincode])));
        if ($place !== '' && ! str_contains(mb_strtolower(implode(' ', $lines)), mb_strtolower((string) $location->pincode ?: '~'))) {
            $lines[] = $place;
        }
        $lines[] = 'India';

        return $lines;
    }

    // Zoho style: "Indian Rupee Two Lakh Seventy Thousand Only".
    public static function amountInWords(float $amount): string
    {
        $rupees = (int) floor(max($amount, 0));
        $paise = (int) round((max($amount, 0) - $rupees) * 100);
        if ($paise === 100) {
            $rupees++;
            $paise = 0;
        }

        return 'Indian Rupee '.self::words($rupees).($paise ? ' and '.self::words($paise).' Paise' : '').' Only';
    }

    public static function money(float|string|null $n): string
    {
        return self::indian((float) $n);
    }

    // Indian grouping like Zoho: 2,74,600.00
    private static function indian(float $n): string
    {
        $sign = $n < 0 ? '-' : '';
        [$int, $dec] = explode('.', number_format(abs($n), 2, '.', ''));
        if (strlen($int) > 3) {
            $last = substr($int, -3);
            $rest = substr($int, 0, -3);
            $int = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest).','.$last;
        }

        return $sign.$int.'.'.$dec;
    }

    private static function words(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }
        $parts = [[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']];
        foreach ($parts as [$size, $name]) {
            if ($n >= $size) {
                return self::words(intdiv($n, $size)).' '.$name.($n % $size ? ' '.self::words($n % $size) : '');
            }
        }
        $ones = ['Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = [2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty', 6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'];
        if ($n < 20) {
            return $ones[$n];
        }

        return $tens[intdiv($n, 10)].($n % 10 ? '-'.$ones[$n % 10] : '');
    }
}
