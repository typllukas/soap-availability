<?php

declare(strict_types=1);

namespace App\StockAvailability\Server;

use App\StockAvailability\Contract\CreateOrderRequest;
use App\StockAvailability\Contract\CreateOrderResponse;
use App\StockAvailability\Contract\GetStockAvailabilityRequest;
use App\StockAvailability\Contract\GetStockAvailabilityResponse;
use App\StockAvailability\StockAvailabilityContract;
use Psr\Log\LoggerInterface;
use SoapFault;
use Throwable;

use function sprintf;

/**
 * ext-soap answers a PHP Error inside an operation itself, so without this wrapper a bug would reach
 * the partner as a fault and never reach the log.
 */
final readonly class LoggingStockAvailabilityOperations
{
    public function __construct(
        private StockAvailabilityOperations $stockAvailabilityOperations,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws SoapFault
     */
    public function getStockAvailability(GetStockAvailabilityRequest $request): GetStockAvailabilityResponse
    {
        try {
            return $this->stockAvailabilityOperations->getStockAvailability($request);
        } catch (SoapFault $soapFault) {
            throw $soapFault;
        } catch (Throwable $throwable) {
            $this->logFailure('getStockAvailability', $throwable);

            throw new SoapFault('Server', StockAvailabilityContract::SERVER_FAULT_STRING);
        }
    }

    /**
     * @throws SoapFault
     */
    public function createOrder(CreateOrderRequest $request): CreateOrderResponse
    {
        try {
            return $this->stockAvailabilityOperations->createOrder($request);
        } catch (SoapFault $soapFault) {
            throw $soapFault;
        } catch (Throwable $throwable) {
            $this->logFailure('createOrder', $throwable);

            throw new SoapFault('Server', StockAvailabilityContract::SERVER_FAULT_STRING);
        }
    }

    private function logFailure(string $operationName, Throwable $throwable): void
    {
        $this->logger->error(
            'SOAP operation {operation_name} failed: {exception_class}: {exception_message} at {exception_location}',
            [
                'operation_name' => $operationName,
                'exception_class' => $throwable::class,
                'exception_message' => $throwable->getMessage(),
                'exception_location' => sprintf('%s:%d', $throwable->getFile(), $throwable->getLine()),
            ],
        );
    }
}
