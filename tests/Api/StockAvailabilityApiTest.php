<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\StockAvailability\Client\StockAvailabilityClient;
use App\StockAvailability\Client\StockAvailabilityFaultException;
use App\StockAvailability\Contract\CreateOrderRequest;
use App\StockAvailability\Contract\GetStockAvailabilityRequest;
use App\StockAvailability\Contract\OrderItem;
use App\StockAvailability\Contract\StockAvailabilityFaultCode;
use App\Tests\KernelSoapClient;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class StockAvailabilityApiTest extends WebTestCase
{
    private KernelBrowser $browser;

    private StockAvailabilityClient $stockAvailabilityClient;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->stockAvailabilityClient = new StockAvailabilityClient(new KernelSoapClient($this->browser));
    }

    /**
     * @param list<string> $productCodes
     */
    private function buildAvailabilityRequest(array $productCodes): GetStockAvailabilityRequest
    {
        $request = new GetStockAvailabilityRequest();
        $request->partnerId = 'P1';
        $request->productCode = $productCodes;

        return $request;
    }

    private function buildOrderRequest(string $productCode, int $quantity): CreateOrderRequest
    {
        $orderItem = new OrderItem();
        $orderItem->productCode = $productCode;
        $orderItem->quantity = $quantity;

        $request = new CreateOrderRequest();
        $request->partnerId = 'P1';
        $request->item = [$orderItem];

        return $request;
    }

    private function readResponseContent(): string
    {
        $content = $this->browser->getResponse()->getContent();
        if ($content === false) {
            throw new LogicException('The controller always answers with a buffered string response.');
        }

        return $content;
    }

    public function testTheWsdlIsServedOnTheEndpoint(): void
    {
        $this->browser->request('GET', '/stock-availability?wsdl');

        $response = $this->browser->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringStartsWith('text/xml', $response->headers->get('Content-Type') ?? '');
        self::assertStringContainsString('name="StockAvailability"', $this->readResponseContent());
    }

    public function testTheServedContractCarriesTheAddressItWasFetchedFrom(): void
    {
        $this->browser->request('GET', 'http://partner.example.com/stock-availability?wsdl');

        self::assertStringContainsString(
            '<soap:address location="http://partner.example.com/stock-availability"/>',
            $this->readResponseContent(),
        );
    }

    public function testAGetWithoutWsdlIsNotFound(): void
    {
        $this->browser->request('GET', '/stock-availability');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->browser->getResponse()->getStatusCode());
    }

    public function testTheContractIsAlsoServedOnTheUppercaseSpelling(): void
    {
        $this->browser->request('GET', '/stock-availability?WSDL');

        self::assertSame(Response::HTTP_OK, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('name="StockAvailability"', $this->readResponseContent());
    }

    public function testASoap12FaultKeepsItsStatusAndContentType(): void
    {
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"'
            . ' xmlns:ns1="urn:soap-availability:stock:v1">'
            . '<env:Body><ns1:getStockAvailability>'
            . '<ns1:partnerId>P1</ns1:partnerId><ns1:productCode>NOPE</ns1:productCode>'
            . '</ns1:getStockAvailability></env:Body>'
            . '</env:Envelope>';

        $this->browser->request(
            'POST',
            '/stock-availability',
            [],
            [],
            ['CONTENT_TYPE' => 'application/soap+xml; charset=utf-8'],
            $envelope,
        );

        $response = $this->browser->getResponse();
        // SoapServer answers the version of the envelope, whatever the WSDL binds
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertStringStartsWith('application/soap+xml', $response->headers->get('Content-Type') ?? '');
        self::assertStringContainsString('<env:Value>env:Sender</env:Value>', $this->readResponseContent());
    }

    public function testAvailabilityOfTwoProducts(): void
    {
        $response = $this->stockAvailabilityClient->getStockAvailability(
            $this->buildAvailabilityRequest(['KEYB-01', 'MOUSE-01']),
        );

        self::assertSame(Response::HTTP_OK, $this->browser->getResponse()->getStatusCode());
        self::assertCount(2, $response->product);
        self::assertSame('2499.00', $response->product[0]->price);
        self::assertTrue($response->product[0]->available);
        self::assertFalse($response->product[1]->available);
    }

    public function testAvailabilityOfOneProductIsStillAList(): void
    {
        $response = $this->stockAvailabilityClient->getStockAvailability($this->buildAvailabilityRequest(['KEYB-01']));

        self::assertCount(1, $response->product);
        self::assertSame('KEYB-01', $response->product[0]->productCode);
    }

    public function testAnOrderReturnsItsNumberAndTotal(): void
    {
        $response = $this->stockAvailabilityClient->createOrder($this->buildOrderRequest('KEYB-01', 2));

        self::assertStringStartsWith('ORD-', $response->orderNumber);
        self::assertSame('4998.00', $response->totalPrice);
        self::assertSame('CZK', $response->currency);
    }

    public function testAnUnknownProductIsATypedFaultWithStatus500(): void
    {
        try {
            $this->stockAvailabilityClient->createOrder($this->buildOrderRequest('NOPE', 1));
            self::fail('Expected StockAvailabilityFaultException');
        } catch (StockAvailabilityFaultException $exception) {
            self::assertSame(StockAvailabilityFaultCode::UNKNOWN_PRODUCT, $exception->getFaultCode());
            self::assertSame('NOPE', $exception->getFault()->productCode);
        }

        // SOAP 1.1 sends a fault with HTTP 500
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $this->browser->getResponse()->getStatusCode());
        $responseXml = $this->stockAvailabilityClient->getLastResponseXml();
        self::assertNotNull($responseXml);
        self::assertStringContainsString('<ns1:code>UNKNOWN_PRODUCT</ns1:code>', $responseXml);
    }

    public function testOrderingMoreThanTheStockIsATypedFault(): void
    {
        try {
            $this->stockAvailabilityClient->createOrder($this->buildOrderRequest('MON-27', 4));
            self::fail('Expected StockAvailabilityFaultException');
        } catch (StockAvailabilityFaultException $exception) {
            self::assertSame(StockAvailabilityFaultCode::INSUFFICIENT_STOCK, $exception->getFaultCode());
            self::assertSame('MON-27', $exception->getFault()->productCode);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideMessagesTheSchemaForbids(): array
    {
        return [
            'fractional quantity' => [
                '<ns1:createOrder><ns1:partnerId>P1</ns1:partnerId><ns1:item>'
                . '<ns1:productCode>KEYB-01</ns1:productCode><ns1:quantity>2.9</ns1:quantity>'
                . '</ns1:item></ns1:createOrder>',
                'quantity',
            ],
            'quantity below the minimum' => [
                '<ns1:createOrder><ns1:partnerId>P1</ns1:partnerId><ns1:item>'
                . '<ns1:productCode>KEYB-01</ns1:productCode><ns1:quantity>0</ns1:quantity>'
                . '</ns1:item></ns1:createOrder>',
                'minInclusive',
            ],
            'an element the schema does not declare' => [
                '<ns1:getStockAvailability><ns1:partnerId>P1</ns1:partnerId>'
                . '<ns1:productCode>KEYB-01</ns1:productCode><ns1:bogus/></ns1:getStockAvailability>',
                'bogus',
            ],
            'an operation the contract does not have' => [
                '<ns1:nosuchOperation/>',
                'nosuchOperation',
            ],
        ];
    }

    #[DataProvider('provideMessagesTheSchemaForbids')]
    public function testAMessageTheSchemaForbidsIsRefusedBeforeTheOperationRuns(
        string $payload,
        string $expectedReason,
    ): void {
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:ns1="urn:soap-availability:stock:v1">'
            . '<SOAP-ENV:Body>' . $payload . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';

        $this->browser->request(
            'POST',
            '/stock-availability',
            [],
            [],
            ['CONTENT_TYPE' => 'text/xml; charset=utf-8'],
            $envelope,
        );

        $response = $this->browser->getResponse();
        $faultEnvelope = $this->readResponseContent();
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        // Client, because resending the same message unchanged can never succeed
        self::assertStringContainsString('<faultcode>SOAP-ENV:Client</faultcode>', $faultEnvelope);
        self::assertStringContainsString('<ns1:code>INVALID_REQUEST</ns1:code>', $faultEnvelope);
        self::assertStringContainsString($expectedReason, $faultEnvelope);
    }

    public function testAMissingElementIsAnInvalidRequestClientFaultNotAnHtmlErrorPage(): void
    {
        // no partnerId, which the schema requires
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:ns1="urn:soap-availability:stock:v1">'
            . '<SOAP-ENV:Body><ns1:getStockAvailability>'
            . '<ns1:productCode>KEYB-01</ns1:productCode>'
            . '</ns1:getStockAvailability></SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';

        $this->browser->request(
            'POST',
            '/stock-availability',
            [],
            [],
            ['CONTENT_TYPE' => 'text/xml; charset=utf-8'],
            $envelope,
        );

        $response = $this->browser->getResponse();
        $faultEnvelope = $this->readResponseContent();
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertStringStartsWith('text/xml', $response->headers->get('Content-Type') ?? '');
        self::assertStringContainsString('<faultcode>SOAP-ENV:Client</faultcode>', $faultEnvelope);
        self::assertStringContainsString('<ns1:code>INVALID_REQUEST</ns1:code>', $faultEnvelope);
        self::assertStringNotContainsString('<html', $faultEnvelope);
    }
}
