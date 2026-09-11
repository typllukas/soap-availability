<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DTO\Product;
use App\Service\ProductCatalog;
use PHPUnit\Framework\TestCase;

final class ProductCatalogTest extends TestCase
{
    private ProductCatalog $productCatalog;

    protected function setUp(): void
    {
        $this->productCatalog = new ProductCatalog();
    }

    public function testAKnownCodeIsFound(): void
    {
        $product = $this->productCatalog->find('KEYB-01');

        self::assertInstanceOf(Product::class, $product);
        self::assertSame(12, $product->quantity);
        self::assertSame(249900, $product->priceMinorUnits);
    }

    public function testAnUnknownCodeIsNull(): void
    {
        self::assertNull($this->productCatalog->find('NOPE'));
    }

    public function testTheMouseIsSoldOutSoTheDemoCanShowAnUnavailableProduct(): void
    {
        $product = $this->productCatalog->find('MOUSE-01');

        self::assertInstanceOf(Product::class, $product);
        self::assertSame(0, $product->quantity);
    }
}
