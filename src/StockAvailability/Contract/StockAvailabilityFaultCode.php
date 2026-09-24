<?php

declare(strict_types=1);

namespace App\StockAvailability\Contract;

/**
 * The wire value is the contract, so these mirror the WSDL enumeration verbatim.
 */
enum StockAvailabilityFaultCode: string
{
    case UNKNOWN_PRODUCT = 'UNKNOWN_PRODUCT';
    case INSUFFICIENT_STOCK = 'INSUFFICIENT_STOCK';
    case INVALID_REQUEST = 'INVALID_REQUEST';
}
