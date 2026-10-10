<?php

namespace Tests\Unit;

use App\Services\KitchenQueueService;
use Tests\TestCase;

class KitchenQueueServiceTest extends TestCase
{
    public function test_sample_s3_orders_vip_then_first_then_normal(): void
    {
        $result = app(KitchenQueueService::class)->schedule($this->sampleS3());

        $this->assertSame(['S2', 'S1', 'S3'], $result['order']);
        $this->assertSame('S2', $result['next']['id']);
        $this->assertSame(['S1', 'S3'], array_column($result['remaining'], 'id'));
        $this->assertSame(5, $result['next']['waiting']);
        $this->assertSame(8, $result['selections'][1]['waiting']);
        $this->assertSame(3, $result['selections'][2]['waiting']);
        $this->assertSame(
            'Higher priority (VIP) than S1 (first) and S3 (normal).',
            $result['selections'][0]['reason'],
        );
        $this->assertSame(
            'Higher priority (first) than S3 (normal).',
            $result['selections'][1]['reason'],
        );
        $this->assertSame('Only ticket left in the queue.', $result['selections'][2]['reason']);
        $this->assertSame(['S1', 'S3'], $result['selections'][0]['remaining']);
    }

    public function test_waiting_time_uses_the_supplied_clock(): void
    {
        $input = $this->sampleS3();
        $input['now'] = 20;

        $result = app(KitchenQueueService::class)->schedule($input);

        $this->assertSame(['S2', 'S1', 'S3'], $result['order']);
        $this->assertSame(20, $result['now']);
        $this->assertSame(17, $result['next']['waiting']);
    }

    public function test_equal_priority_uses_creation_time_then_prep_then_id(): void
    {
        $result = app(KitchenQueueService::class)->schedule([
            'now' => 10,
            'tickets' => [
                ['id' => 'B', 'table' => 1, 'created' => 4, 'prep' => 2, 'priority' => 'VIP'],
                ['id' => 'A', 'table' => 2, 'created' => 4, 'prep' => 9, 'priority' => 'VIP'],
                ['id' => 'C', 'table' => 3, 'created' => 1, 'prep' => 30, 'priority' => 'VIP'],
                ['id' => 'D', 'table' => 4, 'created' => 0, 'prep' => 1, 'priority' => 'first'],
            ],
        ]);

        $this->assertSame(['C', 'B', 'A', 'D'], $result['order']);
        $this->assertSame('Same VIP priority as B, A; created earlier.', $result['selections'][0]['reason']);
        $this->assertSame('Same VIP priority as A; shorter preparation time.', $result['selections'][1]['reason']);
    }

    public function test_sample_order_follows_the_configured_ranks(): void
    {
        config()->set('tablesplit.kitchen.priorities', [
            'VIP' => 10,
            'first' => 30,
        ]);

        $result = app(KitchenQueueService::class)->schedule($this->sampleS3());

        $this->assertSame(['S1', 'S2', 'S3'], $result['order']);
    }

    private function sampleS3(): array
    {
        return [
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
        ];
    }
}
