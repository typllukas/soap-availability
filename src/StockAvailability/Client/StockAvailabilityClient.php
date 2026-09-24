<?php

declare(strict_types=1);

namespace App\StockAvailability\Client;

use App\Exception\SoapCallFailedException;
use App\StockAvailability\Contract\CreateOrderRequest;
use App\StockAvailability\Contract\CreateOrderResponse;
use App\StockAvailability\Contract\GetStockAvailabilityRequest;
use App\StockAvailability\Contract\GetStockAvailabilityResponse;
use App\StockAvailability\Contract\StockAvailabilityFault;
use App\StockAvailability\Contract\StockAvailabilityFaultCode;
use SoapClient;
use SoapFault;

use function get_debug_type;
use function get_object_vars;
use function is_object;
use function is_string;
use function sprintf;

final readonly class StockAvailabilityClient
{
    public function __construct(
        private SoapClient $soapClient,
    ) {
    }

    /**
     * @throws StockAvailabilityFaultException
     * @throws SoapCallFailedException
     */
    public function getStockAvailability(GetStockAvailabilityRequest $request): GetStockAvailabilityResponse
    {
        return $this->call('getStockAvailability', $request, GetStockAvailabilityResponse::class);
    }

    /**
     * @throws StockAvailabilityFaultException
     * @throws SoapCallFailedException
     */
    public function createOrder(CreateOrderRequest $request): CreateOrderResponse
    {
        return $this->call('createOrder', $request, CreateOrderResponse::class);
    }

    public function getLastResponseXml(): ?string
    {
        return $this->soapClient->__getLastResponse();
    }

    /**
     * __soapCall because PHPStan rejects a variable method call on SoapClient.
     *
     * @param class-string<TResponse> $responseClass
     *
     * @return TResponse
     *
     * @throws StockAvailabilityFaultException
     * @throws SoapCallFailedException
     *
     * @template TResponse of object
     */
    private function call(string $operationName, object $request, string $responseClass): object
    {
        try {
            $response = $this->soapClient->__soapCall($operationName, [$request]);
        } catch (SoapFault $exception) {
            throw $this->buildContractFault($exception) ?? new SoapCallFailedException(
                sprintf('%s failed: %s', $operationName, $exception->faultstring),
                previous: $exception,
            );
        }

        if (!$response instanceof $responseClass) {
            throw new SoapCallFailedException(
                sprintf('%s returned %s, expected %s.', $operationName, get_debug_type($response), $responseClass),
            );
        }

        return $response;
    }

    /**
     * The classmap is not applied to a fault detail, ext-soap gives stdClass.
     */
    private function buildContractFault(SoapFault $exception): ?StockAvailabilityFaultException
    {
        $detail = $exception->detail;
        if (!is_object($detail)) {
            return null;
        }

        $faultElement = get_object_vars($detail)['StockAvailabilityFault'] ?? null;
        if (!is_object($faultElement)) {
            return null;
        }

        $faultFields = get_object_vars($faultElement);
        $code = $faultFields['code'] ?? null;
        if (!is_string($code) || StockAvailabilityFaultCode::tryFrom($code) === null) {
            return null;
        }

        $productCode = $faultFields['productCode'] ?? null;
        if ($productCode !== null && !is_string($productCode)) {
            return null;
        }

        $fault = new StockAvailabilityFault();
        $fault->code = $code;
        $fault->productCode = $productCode;

        return new StockAvailabilityFaultException($fault, $exception);
    }
}
