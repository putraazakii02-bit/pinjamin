<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class RentalPriceCalculator
{
    public const MICROSECONDS_PER_DAY = 86_400_000_000;
    public const MAX_DECIMAL_CENTS = 999_999_999_999;

    public static function durationMicroseconds(
        CarbonImmutable $start,
        CarbonImmutable $due
    ): int {
        return self::toMicroseconds($due) - self::toMicroseconds($start);
    }

    public static function calculateDurationDays(
        CarbonImmutable $start,
        CarbonImmutable $due
    ): int {
        $difference = self::durationMicroseconds($start, $due);
        $quotient = intdiv($difference, self::MICROSECONDS_PER_DAY);
        $remainder = $difference % self::MICROSECONDS_PER_DAY;

        return $remainder === 0 ? $quotient : $quotient + 1;
    }

    public static function decimalToCents(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');

        return ((int) $whole * 100) + (int) $fraction;
    }

    public static function formatCents(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function toMicroseconds(CarbonImmutable $value): int
    {
        return ($value->getTimestamp() * 1_000_000) + $value->micro;
    }
}
