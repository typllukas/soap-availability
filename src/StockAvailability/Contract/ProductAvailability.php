<?php

declare(strict_types=1);

namespace App\StockAvailability\Contract;

final class ProductAvailability
{
    public string $productCode;

    public int $quantity;

    /**
     * xsd:decimal on the wire; a string in PHP so 249.90 never becomes 249.9
     */
    public string $price;

    public string $currency;

    public bool $available;
}
