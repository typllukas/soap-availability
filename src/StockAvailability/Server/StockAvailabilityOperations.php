<?php

declare(strict_types=1);

namespace App\StockAvailability\Server;

use App\DTO\Product;
use App\Helper\MinorUnitsToDecimal;
use App\Service\ProductCatalog;
use App\StockAvailability\Contract\CreateOrderRequest;
use App\StockAvailability\Contract\CreateOrderResponse;
use App\StockAvailability\Contract\GetStockAvailabilityRequest;
use App\StockAvailability\Contract\GetStockAvailabilityResponse;
use App\StockAvailability\Contract\ProductAvailability;
use App\StockAvailability\Contract\StockAvailabilityFault;
use App\StockAvailability\Contract\StockAvailabilityFaultCode;
use SoapFault;
use Symfony\Component\Uid\Ulid;

use function in_array;
use function sprintf;
use function trim;

/**
 * Enforces what the schema declares and ext-soap does not check.
 */
readonly class StockAvailabilityOperations
{
    public function __construct(
        private ProductCatalog $productCatalog,
    ) {
    }

    /**
     * @throws SoapFault
     */
    public function getStockAvailability(GetStockAvailabilityRequest $request): GetStockAvailabilityResponse
    {
        $this->requirePartnerId($request->partnerId);

        if ($request->productCode === []) {
            throw $this->buildClientFault(
                StockAvailabilityFaultCode::INVALID_REQUEST,
                null,
                'At least one productCode is required.',
            );
        }

        $response = new GetStockAvailabilityResponse();
        foreach ($request->productCode as $productCode) {
            $product = $this->requireProduct($productCode);

            $productAvailability = new ProductAvailability();
            $productAvailability->productCode = $product->productCode;
            $productAvailability->quantity = $product->quantity;
            $productAvailability->price = MinorUnitsToDecimal::transform($product->priceMinorUnits);
            $productAvailability->currency = ProductCatalog::CURRENCY;
            $productAvailability->available = $product->quantity > 0;
            $response->product[] = $productAvailability;
        }

        return $response;
    }

    /**
     * @throws SoapFault
     */
    public function createOrder(CreateOrderRequest $request): CreateOrderResponse
    {
        $this->requirePartnerId($request->partnerId);

        if ($request->item === []) {
            throw $this->buildClientFault(
                StockAvailabilityFaultCode::INVALID_REQUEST,
                null,
                'At least one item is required.',
            );
        }

        $orderedProductCodes = [];
        $totalMinorUnits = 0;
        foreach ($request->item as $orderItem) {
            if ($orderItem->productCode === null) {
                throw $this->buildClientFault(
                    StockAvailabilityFaultCode::INVALID_REQUEST,
                    null,
                    'Every item requires a productCode.',
                );
            }

            if ($orderItem->quantity === null) {
                throw $this->buildClientFault(
                    StockAvailabilityFaultCode::INVALID_REQUEST,
                    $orderItem->productCode,
                    'Every item requires a quantity.',
                );
            }

            if ($orderItem->quantity < 1) {
                throw $this->buildClientFault(
                    StockAvailabilityFaultCode::INVALID_REQUEST,
                    $orderItem->productCode,
                    'quantity must be at least 1.',
                );
            }

            if (in_array($orderItem->productCode, $orderedProductCodes, true)) {
                throw $this->buildClientFault(
                    StockAvailabilityFaultCode::INVALID_REQUEST,
                    $orderItem->productCode,
                    'Each productCode may appear only once per order.',
                );
            }

            $orderedProductCodes[] = $orderItem->productCode;

            $product = $this->requireProduct($orderItem->productCode);

            if ($orderItem->quantity > $product->quantity) {
                throw $this->buildClientFault(
                    StockAvailabilityFaultCode::INSUFFICIENT_STOCK,
                    $product->productCode,
                    sprintf('Only %d of %s in stock.', $product->quantity, $product->productCode),
                );
            }

            $totalMinorUnits += $orderItem->quantity * $product->priceMinorUnits;
        }

        $response = new CreateOrderResponse();
        $response->orderNumber = 'ORD-' . new Ulid()->toBase32();
        $response->totalPrice = MinorUnitsToDecimal::transform($totalMinorUnits);
        $response->currency = ProductCatalog::CURRENCY;

        return $response;
    }

    private function requirePartnerId(?string $partnerId): void
    {
        if ($partnerId !== null && trim($partnerId) !== '') {
            return;
        }

        throw $this->buildClientFault(
            StockAvailabilityFaultCode::INVALID_REQUEST,
            null,
            'partnerId is required and must not be blank.',
        );
    }

    private function requireProduct(string $productCode): Product
    {
        $product = $this->productCatalog->find($productCode);
        if ($product instanceof Product) {
            return $product;
        }

        throw $this->buildClientFault(
            StockAvailabilityFaultCode::UNKNOWN_PRODUCT,
            $productCode,
            sprintf('Unknown product code %s.', $productCode),
        );
    }

    private function buildClientFault(
        StockAvailabilityFaultCode $code,
        ?string $productCode,
        string $message,
    ): SoapFault {
        $fault = new StockAvailabilityFault();
        $fault->code = $code->value;
        $fault->productCode = $productCode;

        return new SoapFault('Client', $message, details: $fault);
    }
}
