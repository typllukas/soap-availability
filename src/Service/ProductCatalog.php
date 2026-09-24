<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Product;

use function array_find;

/**
 * A PHP array in place of a database, subject of this app is the contract, not storage.
 */
final readonly class ProductCatalog
{
    public const string CURRENCY = 'CZK';

    /**
     * @var list<Product>
     */
    private array $products;

    public function __construct()
    {
        $this->products = [
            new Product('KEYB-01', 12, 249900),
            new Product('MOUSE-01', 0, 79900),
            new Product('MON-27', 3, 899000),
            new Product('CABLE-USBC', 240, 19900),
            new Product('HUB-7P', 7, 129900),
        ];
    }

    public function find(string $productCode): ?Product
    {
        return array_find(
            $this->products,
            static fn (Product $product): bool => $product->productCode === $productCode,
        );
    }
}
