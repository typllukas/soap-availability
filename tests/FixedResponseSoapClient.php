<?php

declare(strict_types=1);

namespace App\Tests;

use App\StockAvailability\StockAvailabilityContract;
use SoapClient;

/**
 * Extends SoapClient rather than being mocked: __doRequest is the transport seam, so the encoding, the
 * classmap decoding and the fault handling under test all still run for real.
 */
final class FixedResponseSoapClient extends SoapClient
{
    public function __construct(
        private readonly string $responseXml,
    ) {
        $soapOptions = [...StockAvailabilityContract::buildSoapOptions(), 'trace' => true];

        parent::__construct(StockAvailabilityContract::WSDL_PATH, $soapOptions);
    }

    public function __doRequest(
        string $request,
        string $location,
        string $action,
        int $version,
        bool $oneWay = false,
        ?string $uriParserClass = null,
    ): string {
        return $this->responseXml;
    }
}
