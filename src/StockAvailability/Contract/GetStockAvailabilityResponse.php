<?php

declare(strict_types=1);

namespace App\StockAvailability\Contract;

final class GetStockAvailabilityResponse
{
    /**
     * @var list<ProductAvailability>
     */
    public array $product = [];
}
