<?php

declare(strict_types=1);

namespace App\NumberConversion;

use App\Exception\SoapCallFailedException;
use SoapFault;

use function get_debug_type;
use function get_object_vars;
use function ini_set;
use function is_object;
use function is_string;
use function strval;

/**
 * The SoapClient is built per call: its constructor downloads the WSDL, and a service wired into the
 * container must not reach the network.
 */
final readonly class NumberConversionClient
{
    private const int READ_TIMEOUT_SECONDS = 10;

    public function __construct(
        private NumberConversionSoapClientFactory $numberConversionSoapClientFactory,
    ) {
    }

    /**
     * @throws SoapCallFailedException
     */
    public function numberToDollars(string $amount): string
    {
        // ext-soap has no read timeout option, only this ini, so a stalled service hangs for its default
        $previousSocketTimeout = ini_set('default_socket_timeout', strval(self::READ_TIMEOUT_SECONDS));

        try {
            $soapClient = $this->numberConversionSoapClientFactory->create();
            $response = $soapClient->__soapCall('NumberToDollars', [
                ['dNum' => $amount],
            ]);
        } catch (SoapFault $exception) {
            throw new SoapCallFailedException(
                'NumberToDollars failed: ' . $exception->faultstring,
                previous: $exception,
            );
        } finally {
            if ($previousSocketTimeout !== false) {
                ini_set('default_socket_timeout', $previousSocketTimeout);
            }
        }

        if (!is_object($response)) {
            throw new SoapCallFailedException(
                'NumberToDollars returned ' . get_debug_type($response) . ', expected an object.',
            );
        }

        $responseFields = get_object_vars($response);
        if (!isset($responseFields['NumberToDollarsResult'])) {
            throw new SoapCallFailedException('NumberToDollars returned no NumberToDollarsResult field.');
        }

        $amountInWords = $responseFields['NumberToDollarsResult'];
        if (!is_string($amountInWords)) {
            throw new SoapCallFailedException(
                'NumberToDollarsResult is ' . get_debug_type($amountInWords) . ', expected a string.',
            );
        }

        return $amountInWords;
    }
}
