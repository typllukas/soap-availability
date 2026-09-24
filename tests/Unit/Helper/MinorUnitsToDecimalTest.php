<?php

declare(strict_types=1);

namespace App\Tests\Unit\Helper;

use App\Helper\MinorUnitsToDecimal;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MinorUnitsToDecimalTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function provideAmounts(): array
    {
        return [
            'whole crowns' => [249900, '2499.00'],
            'trailing zero is kept' => [24990, '249.90'],
            'single digit minor units' => [105, '1.05'],
            'zero' => [0, '0.00'],
        ];
    }

    #[DataProvider('provideAmounts')]
    public function testItFormatsMinorUnitsAsADecimalString(int $minorUnits, string $expectedDecimal): void
    {
        self::assertSame($expectedDecimal, MinorUnitsToDecimal::transform($minorUnits));
    }

    public function testItRejectsNegativeMinorUnits(): void
    {
        $this->expectException(LogicException::class);

        MinorUnitsToDecimal::transform(-50);
    }
}
