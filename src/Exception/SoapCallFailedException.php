<?php

declare(strict_types=1);

namespace App\Exception;

use Exception;

/**
 * Anything that is not a fault of the contract.
 */
final class SoapCallFailedException extends Exception
{
}
