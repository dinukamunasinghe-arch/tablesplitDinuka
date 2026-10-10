<?php

namespace App\Support;

use InvalidArgumentException;

class Decimal
{
    public const SCALE = 12;

    public static function of(string|int|float $value): string
    {
        if (is_int($value)) {
            return bcadd((string) $value, '0', self::SCALE);
        }

        if (is_float($value)) {
            $value = sprintf('%.8F', $value);
        }

        $value = trim($value);

        if ($value === '' || ! is_numeric($value)) {
            throw new InvalidArgumentException("Invalid number [{$value}].");
        }

        return bcadd($value, '0', self::SCALE);
    }

    public static function add(string $left, string $right): string
    {
        return bcadd(self::of($left), self::of($right), self::SCALE);
    }

    public static function sub(string $left, string $right): string
    {
        return bcsub(self::of($left), self::of($right), self::SCALE);
    }

    public static function mul(string $left, string $right): string
    {
        return bcmul(self::of($left), self::of($right), self::SCALE);
    }

    public static function div(string $left, string $right): string
    {
        $divisor = self::of($right);

        if (bccomp($divisor, '0', self::SCALE) === 0) {
            throw new InvalidArgumentException('Cannot divide by zero.');
        }

        return bcdiv(self::of($left), $divisor, self::SCALE);
    }

    public static function cmp(string $left, string $right, int $scale = self::SCALE): int
    {
        return bccomp(self::of($left), self::of($right), $scale);
    }

    public static function isZero(string $amount): bool
    {
        return self::cmp($amount, '0') === 0;
    }

    public static function roundHalfUp(string $amount, int $places = 0): string
    {
        $normalized = self::of($amount);
        $negative = bccomp($normalized, '0', self::SCALE) < 0;
        $absolute = $negative ? bcmul($normalized, '-1', self::SCALE) : $normalized;
        $factor = bcpow('10', (string) $places, 0);
        $shifted = bcmul($absolute, $factor, self::SCALE);
        $bumped = bcadd($shifted, '0.5', self::SCALE);
        $truncated = bcdiv($bumped, '1', 0);
        $rounded = $places === 0 ? $truncated : bcdiv($truncated, $factor, $places);

        return $negative ? '-'.$rounded : $rounded;
    }

    public static function floorAndFraction(string $amount, int $places): array
    {
        $factor = bcpow('10', (string) $places, 0);
        $scaled = bcmul(self::of($amount), $factor, self::SCALE);
        $floor = bcdiv($scaled, '1', 0);
        $fraction = bcsub($scaled, $floor, self::SCALE);

        return [$floor, $fraction];
    }

    public static function format(string $amount): string
    {
        $normalized = self::of($amount);
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $fraction = rtrim($fraction, '0');

        if (strlen($fraction) < 2) {
            $fraction = str_pad($fraction, 2, '0');
        }

        $formatted = $whole.'.'.$fraction;

        return $negative ? '-'.$formatted : $formatted;
    }
}
