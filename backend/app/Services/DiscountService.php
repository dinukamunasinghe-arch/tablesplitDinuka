<?php

namespace App\Services;

use App\Support\Decimal;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DiscountService
{
    public function resolve(?string $code, string $subtotal, array $lines): array
    {
        if ($code === null || $code === '') {
            return [
                'code' => null,
                'amount' => Decimal::of('0'),
                'categories' => [],
            ];
        }

        $rule = config("tablesplit.discounts.{$code}");

        if (! is_array($rule)) {
            throw ValidationException::withMessages([
                'code' => 'The discount code is invalid.',
            ]);
        }

        if (($rule['type'] ?? null) !== 'fixed_before_tax') {
            throw new RuntimeException("Discount type [{$rule['type']}] is not supported.");
        }

        $minimum = Decimal::of($rule['minimum_subtotal'] ?? '0');

        if (Decimal::cmp($subtotal, $minimum) < 0) {
            throw ValidationException::withMessages([
                'code' => "Discount code {$code} is not eligible because the subtotal is below ".Decimal::format($minimum).'.',
            ]);
        }

        $amount = Decimal::of($rule['amount']);
        $categories = array_values($rule['categories'] ?? []);
        $discountable = $this->discountableSubtotal($lines, $categories);

        if (Decimal::cmp($amount, '0') > 0 && Decimal::cmp($discountable, $amount) < 0) {
            throw ValidationException::withMessages([
                'code' => "Discount code {$code} is not eligible because ".Decimal::format($amount).' is greater than the discountable subtotal of '.Decimal::format($discountable).'.',
            ]);
        }

        return [
            'code' => $code,
            'amount' => $amount,
            'categories' => $categories,
        ];
    }

    private function discountableSubtotal(array $lines, array $categories): string
    {
        $total = Decimal::of('0');

        foreach ($lines as $line) {
            if (in_array($line['category'], $categories, true)) {
                $total = Decimal::add($total, $line['gross']);
            }
        }

        return $total;
    }
}
