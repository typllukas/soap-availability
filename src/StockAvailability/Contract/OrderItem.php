<?php

declare(strict_types=1);

namespace App\StockAvailability\Contract;

final class OrderItem
{
    public ?string $productCode = null;

    public ?int $quantity = null;
}
