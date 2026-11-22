<?php

namespace Tests\Unit;

use App\Services\PreciseDecimalFormatter;
use PHPUnit\Framework\TestCase;

class PreciseDecimalFormatterTest extends TestCase
{
    public function test_decimal_strings_keep_four_place_precision_for_large_amounts(): void
    {
        $this->assertSame('576460752303.4235', PreciseDecimalFormatter::toFourDecimalPlaces('576460752303.42354'));
        $this->assertSame('576460752303.4236', PreciseDecimalFormatter::toFourDecimalPlaces('576460752303.42355'));
    }

    public function test_decimal_values_round_half_up_with_sign_and_integer_carry(): void
    {
        $this->assertSame('1.2345', PreciseDecimalFormatter::toFourDecimalPlaces('1.23445'));
        $this->assertSame('-1.2345', PreciseDecimalFormatter::toFourDecimalPlaces('-1.23445'));
        $this->assertSame('10.0000', PreciseDecimalFormatter::toFourDecimalPlaces('9.99995'));
        $this->assertSame('2.0000', PreciseDecimalFormatter::toFourDecimalPlaces(2));
        $this->assertSame('0.1250', PreciseDecimalFormatter::toFourDecimalPlaces(0.125));
        $this->assertSame('1000.0000', PreciseDecimalFormatter::toFourDecimalPlaces('1e3'));
    }

    public function test_non_finite_numeric_strings_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PreciseDecimalFormatter::toFourDecimalPlaces('1e9999');
    }
}
