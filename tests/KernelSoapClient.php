<?php

declare(strict_types=1);

namespace App\Tests;

use App\StockAvailability\StockAvailabilityContract;
use LogicException;
use SoapClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The tests need no web server and no socket: the request goes through the kernel in process.
 */
final class KernelSoapClient extends SoapClient
{
    public function __construct(
        private readonly KernelBrowser $browser,
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
        $this->browser->request(
            'POST',
            '/stock-availability',
            [],
            [],
            [
                'CONTENT_TYPE' => 'text/xml; charset=utf-8',
                'HTTP_SOAPACTION' => $action,
            ],
            $request,
        );

        $response = $this->browser->getResponse();

        $content = $response->getContent();
        if ($content === false) {
            throw new LogicException('The controller always answers with a buffered string response.');
        }

        return $content;
    }
}
