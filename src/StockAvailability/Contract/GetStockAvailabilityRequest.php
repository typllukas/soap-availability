<?php

declare(strict_types=1);

namespace App\StockAvailability\Contract;

final class GetStockAvailabilityRequest
{
    /**
     * Null because ext-soap writes properties directly: an absent element would leave this uninitialized.
     */
    public ?string $partnerId = null;

    /**
     * @var list<string>
     */
    public array $productCode = [];
}
