<?php

namespace App\Services;

use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

class BillSplitService
{
    public function __construct(private DiscountService $discounts) {}

    public function split(array $input): array
    {
        $seatIds = $this->seatIds($input['seats']);
        $gross = $this->emptyBuckets($seatIds);

        foreach ($input['items'] as $item) {
            $lineTotal = Decimal::mul($item['unit_price'], $item['quantity']);
            $shares = $this->splitAmount($lineTotal, $item['shared_by']);

            foreach ($shares as $seatId => $share) {
                $gross[$seatId][$item['category']] = Decimal::add($gross[$seatId][$item['category']], $share);
            }
        }

        $categoryTotals = $this->categoryTotals($gross);
        $subtotal = $this->sumValues($categoryTotals);

        if (Decimal::cmp($subtotal, '0') <= 0) {
            throw ValidationException::withMessages([
                'items' => 'The bill subtotal must be greater than zero.',
            ]);
        }

        $rule = $this->discounts->resolve($input['code'] ?? null, $categoryTotals);
        $discount = $this->discountBySeat($gross, $rule);
        $seats = [];

        foreach ($seatIds as $seatId) {
            $seats[$seatId] = $this->priceSeat($gross[$seatId], $discount[$seatId]);
        }

        $bill = $this->sumSeats($seats, $subtotal, $rule['code']);
        $payable = $this->allocatePayable($seats, $bill['exact_total']);

        return [
            'seats' => $this->presentSeats($seats, $payable['seats']),
            'bill' => [
                'subtotal' => Decimal::format($bill['subtotal']),
                'discount_code' => $bill['discount_code'],
                'discount' => Decimal::format($bill['discount']),
                'service_charge' => Decimal::format($bill['service_charge']),
                'vat' => Decimal::format($bill['vat']),
                'taxes' => Decimal::format(Decimal::add($bill['service_charge'], $bill['vat'])),
                'exact_total' => Decimal::format($bill['exact_total']),
                'total' => $payable['total'],
            ],
        ];
    }

    private function seatIds(array $seats): array
    {
        $ids = array_map('intval', $seats);
        sort($ids);

        return $ids;
    }

    private function categories(): array
    {
        return array_keys(config('tablesplit.categories'));
    }

    private function emptyBuckets(array $seatIds): array
    {
        $buckets = [];
        $zero = [];

        foreach ($this->categories() as $category) {
            $zero[$category] = Decimal::of('0');
        }

        foreach ($seatIds as $seatId) {
            $buckets[$seatId] = $zero;
        }

        return $buckets;
    }

    private function splitAmount(string $amount, array $seatIds): array
    {
        $seatIds = array_values(array_unique(array_map('intval', $seatIds)));
        sort($seatIds);
        $seatCount = count($seatIds);
        $evenShare = Decimal::div($amount, (string) $seatCount);
        $shares = [];
        $assigned = Decimal::of('0');

        foreach ($seatIds as $index => $seatId) {
            if ($index === $seatCount - 1) {
                $shares[$seatId] = Decimal::sub($amount, $assigned);

                continue;
            }

            $shares[$seatId] = $evenShare;
            $assigned = Decimal::add($assigned, $evenShare);
        }

        return $shares;
    }

    private function categoryTotals(array $gross): array
    {
        $totals = [];

        foreach ($this->categories() as $category) {
            $totals[$category] = Decimal::of('0');
        }

        foreach ($gross as $bucket) {
            foreach ($bucket as $category => $amount) {
                $totals[$category] = Decimal::add($totals[$category], $amount);
            }
        }

        return $totals;
    }

    private function discountBySeat(array $gross, array $rule): array
    {
        $discount = $this->emptyBuckets(array_keys($gross));

        if ($rule['type'] === 'percent_before_tax') {
            foreach ($discount as $seatId => $bucket) {
                foreach ($rule['categories'] as $category) {
                    $discount[$seatId][$category] = Decimal::mul($gross[$seatId][$category], $rule['rate']);
                }
            }

            return $discount;
        }

        if ($rule['type'] !== 'fixed_before_tax' || Decimal::isZero($rule['amount'])) {
            return $discount;
        }

        $seatTotals = [];

        foreach ($gross as $seatId => $bucket) {
            $seatTotals[$seatId] = $this->sumValues($bucket);
        }

        $seatShares = $this->shareByWeight($rule['amount'], $seatTotals);

        foreach ($seatShares as $seatId => $seatDiscount) {
            $weights = [];

            foreach ($rule['categories'] as $category) {
                $weights[$category] = $gross[$seatId][$category];
            }

            $parts = $this->shareByWeight($seatDiscount, $weights);

            foreach ($parts as $category => $part) {
                $discount[$seatId][$category] = $part;
            }
        }

        return $discount;
    }

    private function shareByWeight(string $amount, array $weights): array
    {
        $shares = [];

        foreach ($weights as $key => $weight) {
            $shares[$key] = Decimal::of('0');
        }

        if (Decimal::isZero($amount)) {
            return $shares;
        }

        $keys = [];
        $total = Decimal::of('0');

        foreach ($weights as $key => $weight) {
            if (Decimal::cmp($weight, '0') > 0) {
                $keys[] = $key;
                $total = Decimal::add($total, $weight);
            }
        }

        if ($keys === []) {
            return $shares;
        }

        $last = $keys[array_key_last($keys)];
        $remaining = $amount;

        foreach ($keys as $key) {
            if ($key === $last) {
                $shares[$key] = $remaining;

                continue;
            }

            $share = Decimal::div(Decimal::mul($amount, $weights[$key]), $total);
            $shares[$key] = $share;
            $remaining = Decimal::sub($remaining, $share);
        }

        return $shares;
    }

    private function priceSeat(array $gross, array $discount): array
    {
        $subtotal = $this->sumValues($gross);
        $discountTotal = $this->sumValues($discount);
        $net = Decimal::sub($subtotal, $discountTotal);
        $serviceBase = Decimal::of('0');

        foreach ($gross as $category => $amount) {
            if (config("tablesplit.categories.{$category}.service_charge")) {
                $serviceBase = Decimal::add($serviceBase, Decimal::sub($amount, $discount[$category]));
            }
        }

        $service = Decimal::mul($serviceBase, $this->rate('service_charge_rate'));
        $vat = Decimal::mul(Decimal::add($net, $service), $this->rate('vat_rate'));

        return [
            'subtotal' => $subtotal,
            'discount' => $discountTotal,
            'service_charge' => $service,
            'vat' => $vat,
            'exact_total' => Decimal::add($net, Decimal::add($service, $vat)),
        ];
    }

    private function sumSeats(array $seats, string $subtotal, ?string $code): array
    {
        $bill = [
            'subtotal' => $subtotal,
            'discount_code' => $code,
            'discount' => Decimal::of('0'),
            'service_charge' => Decimal::of('0'),
            'vat' => Decimal::of('0'),
            'exact_total' => Decimal::of('0'),
        ];

        foreach ($seats as $seat) {
            $bill['discount'] = Decimal::add($bill['discount'], $seat['discount']);
            $bill['service_charge'] = Decimal::add($bill['service_charge'], $seat['service_charge']);
            $bill['vat'] = Decimal::add($bill['vat'], $seat['vat']);
            $bill['exact_total'] = Decimal::add($bill['exact_total'], $seat['exact_total']);
        }

        return $bill;
    }

    private function allocatePayable(array $seats, string $billExact): array
    {
        $places = (int) config('tablesplit.final_decimal_places');
        $billTotal = Decimal::roundHalfUp($billExact, $places);
        $factor = bcpow('10', (string) $places, 0);
        $targetUnits = bcmul($billTotal, $factor, 0);
        $rows = [];

        foreach ($seats as $seatId => $seat) {
            [$wholeUnits, $fraction] = Decimal::floorAndFraction($seat['exact_total'], $places);
            $rows[] = [
                'id' => $seatId,
                'units' => $wholeUnits,
                'fraction' => $fraction,
            ];
        }

        $assigned = '0';

        foreach ($rows as $row) {
            $assigned = bcadd($assigned, $row['units'], 0);
        }

        $remaining = (int) bcsub($targetUnits, $assigned, 0);

        if ($remaining < 0) {
            throw ValidationException::withMessages([
                'items' => 'The seat totals could not be rounded to match the bill total.',
            ]);
        }

        usort($rows, function (array $left, array $right): int {
            $byFraction = Decimal::cmp($right['fraction'], $left['fraction'], 8);

            if ($byFraction !== 0) {
                return $byFraction;
            }

            return $left['id'] <=> $right['id'];
        });

        $index = 0;
        $rowCount = count($rows);

        while ($remaining > 0) {
            $rows[$index]['units'] = bcadd($rows[$index]['units'], '1', 0);
            $remaining--;
            $index = ($index + 1) % $rowCount;
        }

        $payable = [];

        foreach ($rows as $row) {
            $payable[$row['id']] = $places === 0
                ? (int) $row['units']
                : Decimal::format(bcdiv($row['units'], $factor, $places));
        }

        return [
            'seats' => $payable,
            'total' => $places === 0 ? (int) $billTotal : Decimal::format($billTotal),
        ];
    }

    private function presentSeats(array $seats, array $payable): array
    {
        ksort($seats);
        $rows = [];

        foreach ($seats as $seatId => $seat) {
            $rows[] = [
                'seat' => $seatId,
                'subtotal' => Decimal::format($seat['subtotal']),
                'discount' => Decimal::format($seat['discount']),
                'service_charge' => Decimal::format($seat['service_charge']),
                'vat' => Decimal::format($seat['vat']),
                'exact_total' => Decimal::format($seat['exact_total']),
                'amount_due' => $payable[$seatId],
            ];
        }

        return $rows;
    }

    private function sumValues(array $values): string
    {
        $total = Decimal::of('0');

        foreach ($values as $value) {
            $total = Decimal::add($total, $value);
        }

        return $total;
    }

    private function rate(string $key): string
    {
        return Decimal::of(config("tablesplit.{$key}"));
    }
}
