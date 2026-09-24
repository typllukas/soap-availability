<?php

declare(strict_types=1);

namespace App\NumberConversion;

use App\Exception\SoapCallFailedException;
use SoapClient;
use SoapFault;

use const SOAP_1_2;

final readonly class NumberConversionSoapClientFactory
{
    // the service's own published WSDL; there is no per environment copy of it, so it is not configuration
    private const string WSDL_URL = 'https://www.dataaccess.com/webservicesserver/NumberConversion.wso?WSDL';

    private const int CONNECTION_TIMEOUT_SECONDS = 5;

    /**
     * SOAP 1.2 because the service offers both bindings and ours is 1.1. connection_timeout covers the
     * connect only; NumberConversionClient bounds the read.
     *
     * @throws SoapCallFailedException
     */
    public function create(): SoapClient
    {
        try {
            // no cache_wsdl: the rule that disables it covers our own contract, not a third party's
            return new SoapClient(self::WSDL_URL, [
                'soap_version' => SOAP_1_2,
                'connection_timeout' => self::CONNECTION_TIMEOUT_SECONDS,
            ]);
        } catch (SoapFault $exception) {
            throw new SoapCallFailedException(
                'NumberConversion WSDL could not be loaded: ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
