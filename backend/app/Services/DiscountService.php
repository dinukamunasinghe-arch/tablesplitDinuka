<?php

namespace App\Services;

use App\Support\Decimal;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DiscountService
{
    public function resolve(?string $code, array $categoryTotals): array
    {
        $subtotal = Decimal::of('0');

        foreach ($categoryTotals as $amount) {
            $subtotal = Decimal::add($subtotal, $amount);
        }

        if ($code === null || $code === '') {
            return $this->none();
        }

        $rule = config("tablesplit.discounts.{$code}");

        if (! is_array($rule)) {
            throw ValidationException::withMessages([
                'code' => 'The discount code is invalid.',
            ]);
        }

        $type = $rule['type'] ?? null;

        if (! in_array($type, ['fixed_before_tax', 'percent_before_tax'], true)) {
            throw new RuntimeException("Discount type [{$type}] is not supported.");
        }

        $minimum = Decimal::of($rule['minimum_subtotal'] ?? '0');

        if (Decimal::cmp($subtotal, $minimum) < 0) {
            throw ValidationException::withMessages([
                'code' => "Discount code {$code} is not eligible because the subtotal is below ".Decimal::format($minimum).'.',
            ]);
        }

        $categories = array_values($rule['categories'] ?? []);
        $discountable = Decimal::of('0');

        foreach ($categories as $category) {
            $discountable = Decimal::add($discountable, $categoryTotals[$category] ?? '0');
        }

        if ($type === 'fixed_before_tax') {
            $amount = Decimal::of($rule['amount'] ?? '0');

            if (Decimal::cmp($amount, '0') > 0 && Decimal::cmp($discountable, $amount) < 0) {
                throw ValidationException::withMessages([
                    'code' => "Discount code {$code} is not eligible because ".Decimal::format($amount).' is greater than the discountable subtotal of '.Decimal::format($discountable).'.',
                ]);
            }

            return [
                'code' => $code,
                'type' => $type,
                'amount' => $amount,
                'rate' => Decimal::of('0'),
                'categories' => $categories,
            ];
        }

        return [
            'code' => $code,
            'type' => $type,
            'amount' => Decimal::of('0'),
            'rate' => Decimal::of($rule['rate'] ?? '0'),
            'categories' => $categories,
        ];
    }

    private function none(): array
    {
        return [
            'code' => null,
            'type' => 'none',
            'amount' => Decimal::of('0'),
            'rate' => Decimal::of('0'),
            'categories' => [],
        ];
    }
}
