<?php

declare(strict_types=1);

namespace App\StockAvailability\Client;

use App\StockAvailability\StockAvailabilityContract;
use SoapClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class StockAvailabilitySoapClientFactory
{
    private const int CONNECTION_TIMEOUT_SECONDS = 5;

    public function __construct(
        #[Autowire(env: 'STOCK_AVAILABILITY_URL')]
        private string $stockAvailabilityUrl,
    ) {
    }

    public function create(): SoapClient
    {
        $soapOptions = [
            ...StockAvailabilityContract::buildSoapOptions(),
            'location' => $this->stockAvailabilityUrl,
            'connection_timeout' => self::CONNECTION_TIMEOUT_SECONDS,
            'trace' => true,
        ];

        return new SoapClient(StockAvailabilityContract::WSDL_PATH, $soapOptions);
    }
}
