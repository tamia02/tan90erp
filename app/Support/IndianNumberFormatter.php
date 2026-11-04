<?php

namespace App\Support;

final class IndianNumberFormatter
{
    public static function format(float|int|string|null $value, int $decimals = 2): string
    {
        $value = (float) ($value ?? 0);
        $decimals = max(0, $decimals);
        $number = number_format(abs($value), $decimals, '.', '');
        [$integer, $fraction] = array_pad(explode('.', $number, 2), 2, null);

        if (strlen($integer) > 3) {
            $lastThree = substr($integer, -3);
            $prefix = substr($integer, 0, -3);
            $integer = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $prefix).','.$lastThree;
        }

        $formatted = ($value < 0 ? '-' : '').$integer;

        return $fraction === null ? $formatted : $formatted.'.'.$fraction;
    }

    public static function quantity(float|int|string|null $value, int $decimals = 3): string
    {
        $formatted = self::format($value, $decimals);

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
