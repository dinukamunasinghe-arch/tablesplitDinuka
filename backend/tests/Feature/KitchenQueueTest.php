<?php

namespace Tests\Feature;

use Tests\TestCase;

class KitchenQueueTest extends TestCase
{
    public function test_sample_s3_endpoint(): void
    {
        $response = $this->postJson('/api/kitchen/queue', [
            'now' => 8,
            'tickets' => [
                [
                    'id' => 'S1',
                    'table' => 1,
                    'created' => 0,
                    'prep' => 10,
                    'priority' => 'first',
                ],
                [
                    'id' => 'S2',
                    'table' => 2,
                    'created' => 3,
                    'prep' => 4,
                    'priority' => 'VIP',
                ],
                [
                    'id' => 'S3',
                    'table' => 1,
                    'created' => 5,
                    'prep' => 6,
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('order', ['S2', 'S1', 'S3']);
        $response->assertJsonPath('next.id', 'S2');
        $response->assertJsonPath('next.waiting', 5);
        $response->assertJsonPath('remaining.0.id', 'S1');
        $response->assertJsonPath('remaining.1.id', 'S3');
        $response->assertJsonPath('selections.0.reason', 'Higher priority (VIP) than S1 (first) and S3 (normal).');
    }

    public function test_invalid_tickets_return_field_errors(): void
    {
        $response = $this->postJson('/api/kitchen/queue', [
            'now' => -1,
            'tickets' => [
                [
                    'id' => 'S1',
                    'table' => 1,
                    'created' => -5,
                    'prep' => 0,
                    'priority' => 'rush',
                ],
                [
                    'id' => 'S1',
                    'table' => 2,
                    'created' => 1,
                    'prep' => 4,
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'The given data was invalid.');
        $response->assertJsonStructure([
            'errors' => [
                'now',
                'tickets.0.created',
                'tickets.0.prep',
                'tickets.0.priority',
                'tickets.1.id',
            ],
        ]);
    }
}
