<?php

declare(strict_types=1);

namespace App\StockAvailability\Client;

use App\StockAvailability\Contract\StockAvailabilityFault;
use App\StockAvailability\Contract\StockAvailabilityFaultCode;
use Exception;
use SoapFault;

final class StockAvailabilityFaultException extends Exception
{
    public function __construct(
        private readonly StockAvailabilityFault $fault,
        SoapFault $previous,
    ) {
        parent::__construct($previous->faultstring, previous: $previous);
    }

    public function getFault(): StockAvailabilityFault
    {
        return $this->fault;
    }

    public function getFaultCode(): StockAvailabilityFaultCode
    {
        return StockAvailabilityFaultCode::from($this->fault->code);
    }
}
