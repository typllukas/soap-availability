<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class Product
{
    public function __construct(
        public string $productCode,
        public int $quantity,
        public int $priceMinorUnits,
    ) {
    }
}
