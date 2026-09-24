<?php

declare(strict_types=1);

namespace App\StockAvailability\Contract;

final class CreateOrderResponse
{
    public string $orderNumber;

    public string $totalPrice;

    public string $currency;
}
