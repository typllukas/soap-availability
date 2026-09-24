<?php

declare(strict_types=1);

namespace App\StockAvailability\Contract;

final class StockAvailabilityFault
{
    /**
     * A StockAvailabilityFaultCode value; a string here because ext-soap does not map to enums
     */
    public string $code;

    public ?string $productCode = null;
}
