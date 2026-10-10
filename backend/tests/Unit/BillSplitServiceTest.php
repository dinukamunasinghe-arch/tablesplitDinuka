<?php

namespace Tests\Unit;

use App\Services\BillSplitService;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BillSplitServiceTest extends TestCase
{
    public function test_sample_s1_splits_the_rounding_difference_to_seat_1(): void
    {
        $result = app(BillSplitService::class)->split([
            'seats' => [1, 2],
            'items' => [
                [
                    'name' => 'Rice & curry',
                    'unit_price' => 650,
                    'quantity' => 2,
                    'category' => 'food',
                    'shared_by' => [1, 2],
                ],
                [
                    'name' => 'Tea',
                    'unit_price' => 150,
                    'quantity' => 2,
                    'category' => 'beverage',
                    'shared_by' => [1, 2],
                ],
            ],
            'code' => null,
        ]);

        $this->assertSame('800.00', $result['seats'][0]['subtotal']);
        $this->assertSame('80.00', $result['seats'][0]['service_charge']);
        $this->assertSame('158.40', $result['seats'][0]['vat']);
        $this->assertSame('1038.40', $result['seats'][0]['exact_total']);
        $this->assertSame(1039, $result['seats'][0]['amount_due']);

        $this->assertSame('1038.40', $result['seats'][1]['exact_total']);
        $this->assertSame(1038, $result['seats'][1]['amount_due']);

        $this->assertSame('1600.00', $result['bill']['subtotal']);
        $this->assertNull($result['bill']['discount_code']);
        $this->assertSame('0.00', $result['bill']['discount']);
        $this->assertSame('160.00', $result['bill']['service_charge']);
        $this->assertSame('316.80', $result['bill']['vat']);
        $this->assertSame('476.80', $result['bill']['taxes']);
        $this->assertSame('2076.80', $result['bill']['exact_total']);
        $this->assertSame(2077, $result['bill']['total']);
        $this->assertSame(2077, $result['seats'][0]['amount_due'] + $result['seats'][1]['amount_due']);
    }

    public function test_sample_s2_applies_table500_and_skips_service_on_alcohol(): void
    {
        $result = app(BillSplitService::class)->split($this->sampleS2());

        $this->assertSame(1806, $result['seats'][0]['amount_due']);
        $this->assertSame(3120, $result['seats'][1]['amount_due']);
        $this->assertSame(3119, $result['seats'][2]['amount_due']);
        $this->assertSame(8045, $result['bill']['total']);
        $this->assertSame(8045, array_sum(array_column($result['seats'], 'amount_due')));

        $this->assertSame('TABLE500', $result['bill']['discount_code']);
        $this->assertSame('6900.00', $result['bill']['subtotal']);
        $this->assertSame('500.00', $result['bill']['discount']);
        $this->assertSame('1500.00', $result['seats'][0]['subtotal']);
        $this->assertSame('2700.00', $result['seats'][1]['subtotal']);
        $this->assertSame('2700.00', $result['seats'][2]['subtotal']);
        $this->assertMoney('417.39130435', $result['bill']['service_charge']);
        $this->assertMoney('1227.13043478', $result['bill']['vat']);
        $this->assertMoney('8044.52173913', $result['bill']['exact_total']);
        $this->assertMoney('1805.91304348', $result['seats'][0]['exact_total']);
        $this->assertMoney('3119.30434783', $result['seats'][1]['exact_total']);
        $this->assertMoney('3119.30434783', $result['seats'][2]['exact_total']);
    }

    public function test_shared_cost_that_does_not_divide_evenly_still_sums_to_the_bill(): void
    {
        $result = app(BillSplitService::class)->split([
            'seats' => [1, 2, 3],
            'items' => [
                [
                    'name' => 'Snack',
                    'unit_price' => 100,
                    'quantity' => 1,
                    'category' => 'food',
                    'shared_by' => [1, 2, 3],
                ],
            ],
        ]);

        $this->assertSame(130, $result['bill']['total']);
        $this->assertSame(44, $result['seats'][0]['amount_due']);
        $this->assertSame(43, $result['seats'][1]['amount_due']);
        $this->assertSame(43, $result['seats'][2]['amount_due']);
    }

    public function test_alcohol_is_charged_vat_without_service(): void
    {
        $result = app(BillSplitService::class)->split([
            'seats' => [1],
            'items' => [
                [
                    'name' => 'Arrack',
                    'unit_price' => 1000,
                    'quantity' => 1,
                    'category' => 'alcohol',
                    'shared_by' => [1],
                ],
            ],
        ]);

        $this->assertSame('0.00', $result['bill']['service_charge']);
        $this->assertSame('180.00', $result['bill']['vat']);
        $this->assertSame(1180, $result['bill']['total']);
        $this->assertSame(1180, $result['seats'][0]['amount_due']);
    }

    public function test_rates_and_the_alcohol_rule_come_from_config(): void
    {
        config()->set('tablesplit.service_charge_rate', '0');
        config()->set('tablesplit.vat_rate', '0');

        $withoutTax = app(BillSplitService::class)->split([
            'seats' => [1, 2],
            'items' => [
                [
                    'name' => 'Rice & curry',
                    'unit_price' => 650,
                    'quantity' => 2,
                    'category' => 'food',
                    'shared_by' => [1, 2],
                ],
                [
                    'name' => 'Tea',
                    'unit_price' => 150,
                    'quantity' => 2,
                    'category' => 'beverage',
                    'shared_by' => [1, 2],
                ],
            ],
        ]);

        $this->assertSame(1600, $withoutTax['bill']['total']);

        config()->set('tablesplit.service_charge_rate', '0.10');
        config()->set('tablesplit.vat_rate', '0.18');
        config()->set('tablesplit.categories.alcohol.service_charge', true);

        $withServiceOnAlcohol = app(BillSplitService::class)->split($this->sampleS2());

        $this->assertNotSame(8045, $withServiceOnAlcohol['bill']['total']);
    }

    public function test_table500_amount_comes_from_config(): void
    {
        config()->set('tablesplit.discounts.TABLE500.amount', '0');

        $discounted = app(BillSplitService::class)->split($this->sampleS2());
        $plain = app(BillSplitService::class)->split(array_merge($this->sampleS2(), ['code' => null]));

        $this->assertSame($plain['bill']['total'], $discounted['bill']['total']);
    }

    public function test_happy20_discounts_beverage_only(): void
    {
        $result = app(BillSplitService::class)->split([
            'seats' => [1],
            'items' => [
                [
                    'name' => 'Rice',
                    'unit_price' => 1000,
                    'quantity' => 1,
                    'category' => 'food',
                    'shared_by' => [1],
                ],
                [
                    'name' => 'Tea',
                    'unit_price' => 1000,
                    'quantity' => 1,
                    'category' => 'beverage',
                    'shared_by' => [1],
                ],
                [
                    'name' => 'Arrack',
                    'unit_price' => 1000,
                    'quantity' => 1,
                    'category' => 'alcohol',
                    'shared_by' => [1],
                ],
            ],
            'code' => 'HAPPY20',
        ]);

        $this->assertSame('200.00', $result['seats'][0]['discount']);
        $this->assertSame('180.00', $result['bill']['service_charge']);
        $this->assertSame('536.40', $result['bill']['vat']);
        $this->assertSame('3516.40', $result['bill']['exact_total']);
        $this->assertSame(3516, $result['bill']['total']);
        $this->assertSame(3516, $result['seats'][0]['amount_due']);
    }

    public function test_a_seat_with_no_items_pays_zero(): void
    {
        $result = app(BillSplitService::class)->split([
            'seats' => [1, 2],
            'items' => [
                [
                    'name' => 'Tea',
                    'unit_price' => 100,
                    'quantity' => 1,
                    'category' => 'beverage',
                    'shared_by' => [1],
                ],
            ],
        ]);

        $this->assertSame(2, count($result['seats']));
        $this->assertSame(2, $result['seats'][1]['seat']);
        $this->assertSame('0.00', $result['seats'][1]['subtotal']);
        $this->assertSame(0, $result['seats'][1]['amount_due']);
        $this->assertSame(
            $result['bill']['total'],
            $result['seats'][0]['amount_due'] + $result['seats'][1]['amount_due'],
        );
    }

    public function test_table500_is_rejected_when_the_subtotal_is_below_5000(): void
    {
        $this->expectException(ValidationException::class);

        app(BillSplitService::class)->split([
            'seats' => [1],
            'items' => [
                [
                    'name' => 'Tea',
                    'unit_price' => 100,
                    'quantity' => 1,
                    'category' => 'beverage',
                    'shared_by' => [1],
                ],
            ],
            'code' => 'TABLE500',
        ]);
    }

    private function sampleS2(): array
    {
        return [
            'seats' => [1, 2, 3],
            'items' => [
                [
                    'name' => 'Fried rice',
                    'unit_price' => 1200,
                    'quantity' => 3,
                    'category' => 'food',
                    'shared_by' => [1, 2, 3],
                ],
                [
                    'name' => 'Arrack',
                    'unit_price' => 2400,
                    'quantity' => 1,
                    'category' => 'alcohol',
                    'shared_by' => [2, 3],
                ],
                [
                    'name' => 'Coke',
                    'unit_price' => 300,
                    'quantity' => 3,
                    'category' => 'beverage',
                    'shared_by' => [1, 2, 3],
                ],
            ],
            'code' => 'TABLE500',
        ];
    }

    private function assertMoney(string $expected, string $actual): void
    {
        $this->assertSame(
            0,
            Decimal::cmp($actual, $expected, 4),
            "Expected {$expected}, got {$actual}.",
        );
    }
}
