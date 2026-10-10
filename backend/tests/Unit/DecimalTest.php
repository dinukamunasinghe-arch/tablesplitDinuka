<?php

namespace Tests\Unit;

use App\Support\Decimal;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    public function test_round_half_up_matches_the_bill_samples(): void
    {
        $this->assertSame('2077', Decimal::roundHalfUp('2076.80', 0));
        $this->assertSame('8045', Decimal::roundHalfUp('8044.52173913', 0));
        $this->assertSame('1038', Decimal::roundHalfUp('1038.40', 0));
        $this->assertSame('2', Decimal::roundHalfUp('1.5', 0));
        $this->assertSame('1', Decimal::roundHalfUp('1.4999', 0));
    }

    public function test_money_math_does_not_use_binary_floats(): void
    {
        $this->assertSame('0.30', Decimal::format(Decimal::mul('0.10', '3')));
        $this->assertSame('316.80', Decimal::format(Decimal::mul('1760', '0.18')));
    }
}
