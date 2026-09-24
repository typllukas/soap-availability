<?php

declare(strict_types=1);

namespace App\Tests\Unit\StockAvailability\Server;

use App\Service\ProductCatalog;
use App\StockAvailability\Contract\CreateOrderRequest;
use App\StockAvailability\Contract\GetStockAvailabilityRequest;
use App\StockAvailability\Contract\OrderItem;
use App\StockAvailability\Contract\StockAvailabilityFault;
use App\StockAvailability\Contract\StockAvailabilityFaultCode;
use App\StockAvailability\Server\LoggingStockAvailabilityOperations;
use App\StockAvailability\Server\StockAvailabilityOperations;
use Error;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SoapFault;

final class LoggingStockAvailabilityOperationsTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function buildLoggingOperations(): LoggingStockAvailabilityOperations
    {
        return new LoggingStockAvailabilityOperations(
            new StockAvailabilityOperations(new ProductCatalog()),
            $this->logger,
        );
    }

    /**
     * A double is the only way in: the real operations answer every input with a fault of their own.
     */
    private function buildLoggingOperationsFailingIn(string $operationName): LoggingStockAvailabilityOperations
    {
        $failingOperations = self::createStub(StockAvailabilityOperations::class);
        $failingOperations->method($operationName)->willThrowException(new Error('a bug inside the operation'));

        return new LoggingStockAvailabilityOperations($failingOperations, $this->logger);
    }

    private function buildOrderRequest(string $productCode): CreateOrderRequest
    {
        $orderItem = new OrderItem();
        $orderItem->productCode = $productCode;
        $orderItem->quantity = 1;

        $request = new CreateOrderRequest();
        $request->partnerId = 'P1';
        $request->item = [$orderItem];

        return $request;
    }

    public function testABugInGetStockAvailabilityIsLoggedAndBecomesAServerFault(): void
    {
        $loggedContext = [];
        $this->logger->expects(self::once())
            ->method('error')
            ->willReturnCallback(static function (string $message, array $context) use (&$loggedContext): void {
                $loggedContext = $context;
            });

        $request = new GetStockAvailabilityRequest();
        $request->partnerId = 'P1';
        $request->productCode = ['KEYB-01'];

        $loggingOperations = $this->buildLoggingOperationsFailingIn('getStockAvailability');

        try {
            $loggingOperations->getStockAvailability($request);
            self::fail('Expected a SoapFault');
        } catch (SoapFault $exception) {
            self::assertSame('Server', $exception->faultcode);
            self::assertSame('Internal Error', $exception->faultstring);
        }

        self::assertSame('getStockAvailability', $loggedContext['operation_name']);
        self::assertSame(Error::class, $loggedContext['exception_class']);
        self::assertSame('a bug inside the operation', $loggedContext['exception_message']);
    }

    public function testABugInCreateOrderIsLoggedAndBecomesAServerFault(): void
    {
        $loggedContext = [];
        $this->logger->expects(self::once())
            ->method('error')
            ->willReturnCallback(static function (string $message, array $context) use (&$loggedContext): void {
                $loggedContext = $context;
            });

        $loggingOperations = $this->buildLoggingOperationsFailingIn('createOrder');

        try {
            $loggingOperations->createOrder($this->buildOrderRequest('KEYB-01'));
            self::fail('Expected a SoapFault');
        } catch (SoapFault $exception) {
            self::assertSame('Server', $exception->faultcode);
            self::assertSame('Internal Error', $exception->faultstring);
        }

        self::assertSame('createOrder', $loggedContext['operation_name']);
        self::assertSame(Error::class, $loggedContext['exception_class']);
        self::assertSame('a bug inside the operation', $loggedContext['exception_message']);
    }

    public function testAFaultOfTheContractPassesThroughUnloggedAndUnchanged(): void
    {
        $this->logger->expects(self::never())->method('error');

        $loggingOperations = $this->buildLoggingOperations();

        try {
            $loggingOperations->createOrder($this->buildOrderRequest('NOPE'));
            self::fail('Expected a SoapFault');
        } catch (SoapFault $exception) {
            self::assertSame('Client', $exception->faultcode);
            self::assertInstanceOf(StockAvailabilityFault::class, $exception->detail);
            self::assertSame(StockAvailabilityFaultCode::UNKNOWN_PRODUCT->value, $exception->detail->code);
        }
    }

    public function testAValidRequestIsDelegatedToTheOperations(): void
    {
        $this->logger->expects(self::never())->method('error');

        $request = new GetStockAvailabilityRequest();
        $request->partnerId = 'P1';
        $request->productCode = ['KEYB-01'];

        $loggingOperations = $this->buildLoggingOperations();

        $response = $loggingOperations->getStockAvailability($request);

        self::assertCount(1, $response->product);
        self::assertSame('2499.00', $response->product[0]->price);
    }
}
