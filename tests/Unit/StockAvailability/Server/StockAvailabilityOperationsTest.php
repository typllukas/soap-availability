<?php

declare(strict_types=1);

namespace App\Tests\Unit\StockAvailability\Server;

use App\Service\ProductCatalog;
use App\StockAvailability\Contract\CreateOrderRequest;
use App\StockAvailability\Contract\GetStockAvailabilityRequest;
use App\StockAvailability\Contract\OrderItem;
use App\StockAvailability\Contract\StockAvailabilityFault;
use App\StockAvailability\Contract\StockAvailabilityFaultCode;
use App\StockAvailability\Server\StockAvailabilityOperations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SoapFault;

/**
 * The operations without SOAP around them.
 */
final class StockAvailabilityOperationsTest extends TestCase
{
    private StockAvailabilityOperations $operations;

    protected function setUp(): void
    {
        $this->operations = new StockAvailabilityOperations(new ProductCatalog());
    }

    /**
     * @param list<string> $productCodes
     */
    private static function buildAvailabilityRequest(
        ?string $partnerId,
        array $productCodes,
    ): GetStockAvailabilityRequest {
        $request = new GetStockAvailabilityRequest();
        $request->partnerId = $partnerId;
        $request->productCode = $productCodes;

        return $request;
    }

    /**
     * @param list<array{?string, ?int}> $items
     */
    private static function buildOrderRequest(?string $partnerId, array $items): CreateOrderRequest
    {
        $request = new CreateOrderRequest();
        $request->partnerId = $partnerId;
        foreach ($items as [$productCode, $quantity]) {
            $orderItem = new OrderItem();
            $orderItem->productCode = $productCode;
            $orderItem->quantity = $quantity;
            $request->item[] = $orderItem;
        }

        return $request;
    }

    public function testAvailabilityListsEveryRequestedProductWithPriceAsDecimalString(): void
    {
        $availabilityRequest = self::buildAvailabilityRequest('P1', ['KEYB-01', 'MOUSE-01']);
        $response = $this->operations->getStockAvailability($availabilityRequest);

        self::assertCount(2, $response->product);
        self::assertSame('KEYB-01', $response->product[0]->productCode);
        self::assertSame(12, $response->product[0]->quantity);
        self::assertSame('2499.00', $response->product[0]->price);
        self::assertSame('CZK', $response->product[0]->currency);
        self::assertTrue($response->product[0]->available);
        self::assertSame(0, $response->product[1]->quantity);
        self::assertFalse($response->product[1]->available);
    }

    public function testAvailabilityOfAnUnknownProductIsAClientFault(): void
    {
        try {
            $this->operations->getStockAvailability(self::buildAvailabilityRequest('P1', ['KEYB-01', 'NOPE']));
            self::fail('Expected a SoapFault');
        } catch (SoapFault $exception) {
            self::assertSame('Client', $exception->faultcode);
            self::assertInstanceOf(StockAvailabilityFault::class, $exception->detail);
            self::assertSame(StockAvailabilityFaultCode::UNKNOWN_PRODUCT->value, $exception->detail->code);
            self::assertSame('NOPE', $exception->detail->productCode);
        }
    }

    public function testAnOrderReturnsANumberAndTheTotal(): void
    {
        $response = $this->operations->createOrder(self::buildOrderRequest('P1', [['KEYB-01', 2], ['CABLE-USBC', 1]]));

        self::assertStringStartsWith('ORD-', $response->orderNumber);
        self::assertSame('5197.00', $response->totalPrice);
        self::assertSame('CZK', $response->currency);
    }

    public function testAnOrderDoesNotDecrementTheStock(): void
    {
        $availabilityRequest = self::buildAvailabilityRequest('P1', ['KEYB-01']);
        $quantityBeforeOrder = $this->operations->getStockAvailability($availabilityRequest)->product[0]->quantity;

        $response = $this->operations->createOrder(self::buildOrderRequest('P1', [['KEYB-01', 2]]));
        self::assertStringStartsWith('ORD-', $response->orderNumber);

        $quantityAfterOrder = $this->operations->getStockAvailability($availabilityRequest)->product[0]->quantity;
        self::assertSame($quantityBeforeOrder, $quantityAfterOrder);
    }

    public function testOrderingMoreThanTheStockIsAClientFault(): void
    {
        try {
            $this->operations->createOrder(self::buildOrderRequest('P1', [['MON-27', 4]]));
            self::fail('Expected a SoapFault');
        } catch (SoapFault $exception) {
            self::assertSame('Client', $exception->faultcode);
            self::assertInstanceOf(StockAvailabilityFault::class, $exception->detail);
            self::assertSame(StockAvailabilityFaultCode::INSUFFICIENT_STOCK->value, $exception->detail->code);
            self::assertSame('MON-27', $exception->detail->productCode);
        }
    }

    public function testOrderingAnUnknownProductIsAClientFault(): void
    {
        try {
            $this->operations->createOrder(self::buildOrderRequest('P1', [['NOPE', 1]]));
            self::fail('Expected a SoapFault');
        } catch (SoapFault $exception) {
            self::assertSame('Client', $exception->faultcode);
            self::assertInstanceOf(StockAvailabilityFault::class, $exception->detail);
            self::assertSame(StockAvailabilityFaultCode::UNKNOWN_PRODUCT->value, $exception->detail->code);
            self::assertSame('NOPE', $exception->detail->productCode);
        }
    }

    /**
     * @return array<string, array{CreateOrderRequest|GetStockAvailabilityRequest}>
     */
    public static function provideInvalidRequests(): array
    {
        return [
            'absent partnerId element' => [self::buildAvailabilityRequest(null, ['KEYB-01'])],
            'blank partner' => [self::buildAvailabilityRequest('  ', ['KEYB-01'])],
            'no product codes' => [self::buildAvailabilityRequest('P1', [])],
            'no items' => [self::buildOrderRequest('P1', [])],
            'absent partnerId element on an order' => [self::buildOrderRequest(null, [['KEYB-01', 1]])],
            'item without a productCode element' => [self::buildOrderRequest('P1', [[null, 1]])],
            'item without a quantity element' => [self::buildOrderRequest('P1', [['KEYB-01', null]])],
            'zero quantity' => [self::buildOrderRequest('P1', [['KEYB-01', 0]])],
            'duplicate product code' => [self::buildOrderRequest('P1', [['KEYB-01', 1], ['KEYB-01', 1]])],
        ];
    }

    #[DataProvider('provideInvalidRequests')]
    public function testWhatTheSchemaPromisesButExtSoapDoesNotEnforceIsAnInvalidRequestFault(
        CreateOrderRequest|GetStockAvailabilityRequest $request,
    ): void {
        try {
            if ($request instanceof CreateOrderRequest) {
                $this->operations->createOrder($request);
            } else {
                $this->operations->getStockAvailability($request);
            }

            self::fail('Expected a SoapFault');
        } catch (SoapFault $exception) {
            self::assertSame('Client', $exception->faultcode);
            self::assertInstanceOf(StockAvailabilityFault::class, $exception->detail);
            self::assertSame(StockAvailabilityFaultCode::INVALID_REQUEST->value, $exception->detail->code);
        }
    }
}
