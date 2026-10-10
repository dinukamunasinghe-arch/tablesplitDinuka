<?php

namespace Tests\Feature;

use Tests\TestCase;

class SplitBillTest extends TestCase
{
    public function test_sample_s1_endpoint(): void
    {
        $response = $this->postJson('/api/split', [
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

        $response->assertOk();
        $response->assertJsonPath('seats.0.amount_due', 1039);
        $response->assertJsonPath('seats.1.amount_due', 1038);
        $response->assertJsonPath('bill.total', 2077);
        $response->assertJsonPath('bill.discount', '0.00');
    }

    public function test_sample_s2_endpoint(): void
    {
        $response = $this->postJson('/api/split', [
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
        ]);

        $response->assertOk();
        $response->assertJsonPath('seats.0.amount_due', 1806);
        $response->assertJsonPath('seats.1.amount_due', 3120);
        $response->assertJsonPath('seats.2.amount_due', 3119);
        $response->assertJsonPath('bill.total', 8045);
        $response->assertJsonPath('bill.discount', '500.00');
    }

    public function test_unknown_seat_returns_a_field_error(): void
    {
        $response = $this->postJson('/api/split', [
            'seats' => [1, 2],
            'items' => [
                [
                    'name' => 'Rice',
                    'unit_price' => 100,
                    'quantity' => 1,
                    'category' => 'food',
                    'shared_by' => [1, 3],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'The given data was invalid.');
        $this->assertSame(
            'Seat 3 does not exist in the supplied seats.',
            $response->json('errors')['items.0.shared_by.1'][0],
        );
    }

    public function test_invalid_category_price_quantity_and_duplicate_seats_are_rejected(): void
    {
        $response = $this->postJson('/api/split', [
            'seats' => [1, 1],
            'items' => [
                [
                    'name' => 'Tea',
                    'unit_price' => 0,
                    'quantity' => -1,
                    'category' => 'dessert',
                    'shared_by' => [],
                ],
            ],
            'code' => 'SAVE10',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'message',
            'errors' => [
                'seats.1',
                'items.0.unit_price',
                'items.0.quantity',
                'items.0.category',
                'items.0.shared_by',
                'code',
            ],
        ]);
    }

    public function test_a_seat_cannot_share_the_same_item_twice(): void
    {
        $response = $this->postJson('/api/split', [
            'seats' => [1, 2],
            'items' => [
                [
                    'name' => 'Tea',
                    'unit_price' => 100,
                    'quantity' => 1,
                    'category' => 'beverage',
                    'shared_by' => [1, 1],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            'An item cannot list the same seat twice.',
            $response->json('errors')['items.0.shared_by.1'][0],
        );
    }

    public function test_table500_below_the_discount_amount_is_ineligible(): void
    {
        $response = $this->postJson('/api/split', [
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

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'The given data was invalid.');
        $this->assertStringContainsString(
            'not eligible',
            $response->json('errors.code.0'),
        );
    }
}
