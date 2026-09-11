<?php

declare(strict_types=1);

namespace App\StockAvailability\Contract;

final class CreateOrderRequest
{
    public ?string $partnerId = null;

    /**
     * @var list<OrderItem>
     */
    public array $item = [];
}
