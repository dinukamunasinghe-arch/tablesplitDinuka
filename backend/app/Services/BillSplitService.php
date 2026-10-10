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
        $lines = $this->lines($input['items']);
        $subtotal = $this->sum($lines, 'gross');

        if (Decimal::cmp($subtotal, '0') <= 0) {
            throw ValidationException::withMessages([
                'items' => 'The bill subtotal must be greater than zero.',
            ]);
        }

        $discount = $this->discounts->resolve($input['code'] ?? null, $subtotal, $lines);
        $lines = $this->applyDiscount($lines, $discount['amount'], $discount['categories']);

        $net = $this->sum($lines, 'net');
        $service = Decimal::mul($this->serviceBase($lines), $this->rate('service_charge_rate'));
        $vat = Decimal::mul(Decimal::add($net, $service), $this->rate('vat_rate'));
        $exactTotal = Decimal::add($net, Decimal::add($service, $vat));

        $bill = [
            'subtotal' => $subtotal,
            'discount' => $discount['amount'],
            'service_charge' => $service,
            'vat' => $vat,
            'exact_total' => $exactTotal,
        ];

        $seats = $this->seatTotals($seatIds, $lines);
        $this->alignSeatsToBill($seats, $bill);
        $payable = $this->allocatePayable($seats, $bill['exact_total']);

        return [
            'seats' => $this->presentSeats($seats, $payable['seats']),
            'bill' => [
                'subtotal' => Decimal::format($bill['subtotal']),
                'discount_code' => $discount['code'],
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

    private function lines(array $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $sharedBy = array_map('intval', $item['shared_by']);
            sort($sharedBy);

            $lines[] = [
                'category' => $item['category'],
                'gross' => Decimal::mul($item['unit_price'], $item['quantity']),
                'net' => '0',
                'discount' => '0',
                'shared_by' => $sharedBy,
            ];
        }

        return $lines;
    }

    private function applyDiscount(array $lines, string $amount, array $categories): array
    {
        foreach ($lines as $index => $line) {
            $lines[$index]['discount'] = Decimal::of('0');
            $lines[$index]['net'] = $line['gross'];
        }

        if (Decimal::isZero($amount)) {
            return $lines;
        }

        $eligibleIndexes = [];
        $eligibleTotal = Decimal::of('0');

        foreach ($lines as $index => $line) {
            if (in_array($line['category'], $categories, true)) {
                $eligibleIndexes[] = $index;
                $eligibleTotal = Decimal::add($eligibleTotal, $line['gross']);
            }
        }

        $remaining = $amount;
        $lastIndex = $eligibleIndexes[array_key_last($eligibleIndexes)];

        foreach ($eligibleIndexes as $index) {
            if ($index === $lastIndex) {
                $share = $remaining;
            } else {
                $share = Decimal::div(Decimal::mul($amount, $lines[$index]['gross']), $eligibleTotal);
                $remaining = Decimal::sub($remaining, $share);
            }

            $lines[$index]['discount'] = $share;
            $lines[$index]['net'] = Decimal::sub($lines[$index]['gross'], $share);
        }

        return $lines;
    }

    private function serviceBase(array $lines): string
    {
        $base = Decimal::of('0');

        foreach ($lines as $line) {
            if ($this->serviceApplies($line['category'])) {
                $base = Decimal::add($base, $line['net']);
            }
        }

        return $base;
    }

    private function serviceApplies(string $category): bool
    {
        return (bool) config("tablesplit.categories.{$category}.service_charge");
    }

    private function rate(string $key): string
    {
        return Decimal::of(config("tablesplit.{$key}"));
    }

    private function sum(array $lines, string $field): string
    {
        $total = Decimal::of('0');

        foreach ($lines as $line) {
            $total = Decimal::add($total, $line[$field]);
        }

        return $total;
    }

    private function seatTotals(array $seatIds, array $lines): array
    {
        $seats = [];

        foreach ($seatIds as $seatId) {
            $seats[$seatId] = [
                'subtotal' => Decimal::of('0'),
                'discount' => Decimal::of('0'),
                'net' => Decimal::of('0'),
                'service_base' => Decimal::of('0'),
                'service_charge' => Decimal::of('0'),
                'vat' => Decimal::of('0'),
                'exact_total' => Decimal::of('0'),
            ];
        }

        foreach ($lines as $line) {
            $grossShares = $this->splitAmount($line['gross'], $line['shared_by']);
            $netShares = $this->splitAmount($line['net'], $line['shared_by']);
            $discountShares = $this->splitAmount($line['discount'], $line['shared_by']);

            foreach ($grossShares as $seatId => $gross) {
                $seats[$seatId]['subtotal'] = Decimal::add($seats[$seatId]['subtotal'], $gross);
                $seats[$seatId]['net'] = Decimal::add($seats[$seatId]['net'], $netShares[$seatId]);
                $seats[$seatId]['discount'] = Decimal::add($seats[$seatId]['discount'], $discountShares[$seatId]);

                if ($this->serviceApplies($line['category'])) {
                    $seats[$seatId]['service_base'] = Decimal::add(
                        $seats[$seatId]['service_base'],
                        $netShares[$seatId],
                    );
                }
            }
        }

        $serviceRate = $this->rate('service_charge_rate');
        $vatRate = $this->rate('vat_rate');

        foreach ($seats as $seatId => $seat) {
            $service = Decimal::mul($seat['service_base'], $serviceRate);
            $vat = Decimal::mul(Decimal::add($seat['net'], $service), $vatRate);
            $seats[$seatId]['service_charge'] = $service;
            $seats[$seatId]['vat'] = $vat;
            $seats[$seatId]['exact_total'] = Decimal::add($seat['net'], Decimal::add($service, $vat));
        }

        return $seats;
    }

    private function splitAmount(string $amount, array $seatIds): array
    {
        $seatIds = array_values(array_unique($seatIds));
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

    private function alignSeatsToBill(array &$seats, array $bill): void
    {
        $ids = array_keys($seats);
        sort($ids);
        $lastSeatId = $ids[array_key_last($ids)];

        foreach (['discount', 'service_charge', 'vat'] as $field) {
            $others = Decimal::of('0');

            foreach ($ids as $id) {
                if ($id === $lastSeatId) {
                    continue;
                }

                $others = Decimal::add($others, $seats[$id][$field]);
            }

            $seats[$lastSeatId][$field] = Decimal::sub($bill[$field], $others);
        }

        $seats[$lastSeatId]['net'] = Decimal::sub(
            $seats[$lastSeatId]['subtotal'],
            $seats[$lastSeatId]['discount'],
        );

        foreach ($ids as $id) {
            $seats[$id]['exact_total'] = Decimal::add(
                $seats[$id]['net'],
                Decimal::add($seats[$id]['service_charge'], $seats[$id]['vat']),
            );
        }

        $exactSum = Decimal::of('0');

        foreach ($ids as $id) {
            $exactSum = Decimal::add($exactSum, $seats[$id]['exact_total']);
        }

        $drift = Decimal::sub($bill['exact_total'], $exactSum);
        $seats[$lastSeatId]['vat'] = Decimal::add($seats[$lastSeatId]['vat'], $drift);
        $seats[$lastSeatId]['exact_total'] = Decimal::add($seats[$lastSeatId]['exact_total'], $drift);
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
}
