<?php

declare(strict_types=1);

namespace App\Helper;

use LogicException;

use function intdiv;
use function sprintf;

final class MinorUnitsToDecimal
{
    public static function transform(int $minorUnits): string
    {
        if ($minorUnits < 0) {
            throw new LogicException(sprintf('Expected non-negative minor units, got %d.', $minorUnits));
        }

        return sprintf('%d.%02d', intdiv($minorUnits, 100), $minorUnits % 100);
    }
}
