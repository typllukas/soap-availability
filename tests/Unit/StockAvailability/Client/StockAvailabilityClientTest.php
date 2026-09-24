<?php

declare(strict_types=1);

namespace App\Tests\Unit\StockAvailability\Client;

use App\Exception\SoapCallFailedException;
use App\StockAvailability\Client\StockAvailabilityClient;
use App\StockAvailability\Client\StockAvailabilityFaultException;
use App\StockAvailability\Contract\GetStockAvailabilityRequest;
use App\StockAvailability\Contract\GetStockAvailabilityResponse;
use App\StockAvailability\Contract\StockAvailabilityFaultCode;
use App\Tests\FixedResponseSoapClient;
use PHPUnit\Framework\TestCase;

final class StockAvailabilityClientTest extends TestCase
{
    private const string ENVELOPE_OPEN = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
        . ' xmlns:ns1="urn:soap-availability:stock:v1">'
        . '<SOAP-ENV:Body>';

    private const string ENVELOPE_CLOSE = '</SOAP-ENV:Body></SOAP-ENV:Envelope>';

    private function buildAvailabilityRequest(): GetStockAvailabilityRequest
    {
        $request = new GetStockAvailabilityRequest();
        $request->partnerId = 'P1';
        $request->productCode = ['KEYB-01'];

        return $request;
    }

    public function testAResponseComesBackAsTypedObjectsWithTheDecimalKept(): void
    {
        $client = new StockAvailabilityClient(new FixedResponseSoapClient(
            self::ENVELOPE_OPEN
            . '<ns1:getStockAvailabilityResponse><ns1:product>'
            . '<ns1:productCode>KEYB-01</ns1:productCode>'
            . '<ns1:quantity>12</ns1:quantity>'
            . '<ns1:price>2499.00</ns1:price>'
            . '<ns1:currency>CZK</ns1:currency>'
            . '<ns1:available>true</ns1:available>'
            . '</ns1:product></ns1:getStockAvailabilityResponse>'
            . self::ENVELOPE_CLOSE,
        ));

        $response = $client->getStockAvailability($this->buildAvailabilityRequest());

        self::assertCount(1, $response->product);
        self::assertSame('2499.00', $response->product[0]->price);
        self::assertTrue($response->product[0]->available);
    }

    public function testTheRequestGoesOutAsAWrappedDocumentLiteralBody(): void
    {
        $soapClient = new FixedResponseSoapClient(
            self::ENVELOPE_OPEN . '<ns1:getStockAvailabilityResponse/>' . self::ENVELOPE_CLOSE,
        );
        $client = new StockAvailabilityClient($soapClient);
        $request = $this->buildAvailabilityRequest();
        $request->productCode = ['KEYB-01', 'MOUSE-01'];

        $client->getStockAvailability($request);
        $requestXml = $soapClient->__getLastRequest();

        // one element per array member, which is why decoding needs SOAP_SINGLE_ELEMENT_ARRAYS
        self::assertNotNull($requestXml);
        self::assertStringContainsString(
            '<SOAP-ENV:Body>'
            . '<ns1:getStockAvailability>'
            . '<ns1:partnerId>P1</ns1:partnerId>'
            . '<ns1:productCode>KEYB-01</ns1:productCode>'
            . '<ns1:productCode>MOUSE-01</ns1:productCode>'
            . '</ns1:getStockAvailability>'
            . '</SOAP-ENV:Body>',
            $requestXml,
        );
    }

    public function testABodyThatIsNotTheDeclaredResponseElementIsAFailedCall(): void
    {
        // ext-soap decodes only the element the operation declares, so another body decodes to nothing
        $client = new StockAvailabilityClient(new FixedResponseSoapClient(
            self::ENVELOPE_OPEN
            . '<ns1:createOrderResponse>'
            . '<ns1:orderNumber>ORD-1</ns1:orderNumber>'
            . '<ns1:totalPrice>1.00</ns1:totalPrice>'
            . '<ns1:currency>CZK</ns1:currency>'
            . '</ns1:createOrderResponse>'
            . self::ENVELOPE_CLOSE,
        ));

        $this->expectException(SoapCallFailedException::class);
        $this->expectExceptionMessage(
            'getStockAvailability returned null, expected ' . GetStockAvailabilityResponse::class,
        );

        $client->getStockAvailability($this->buildAvailabilityRequest());
    }

    public function testAFaultOfTheContractIsATypedException(): void
    {
        $client = new StockAvailabilityClient(new FixedResponseSoapClient(
            self::ENVELOPE_OPEN
            . '<SOAP-ENV:Fault><faultcode>SOAP-ENV:Client</faultcode>'
            . '<faultstring>Unknown product code NOPE.</faultstring>'
            . '<detail><ns1:StockAvailabilityFault>'
            . '<ns1:code>UNKNOWN_PRODUCT</ns1:code>'
            . '<ns1:productCode>NOPE</ns1:productCode>'
            . '</ns1:StockAvailabilityFault></detail></SOAP-ENV:Fault>'
            . self::ENVELOPE_CLOSE,
        ));

        try {
            $client->getStockAvailability($this->buildAvailabilityRequest());
            self::fail('Expected StockAvailabilityFaultException');
        } catch (StockAvailabilityFaultException $exception) {
            self::assertSame(StockAvailabilityFaultCode::UNKNOWN_PRODUCT, $exception->getFaultCode());
            self::assertSame('NOPE', $exception->getFault()->productCode);
            self::assertSame('Unknown product code NOPE.', $exception->getMessage());
        }
    }

    public function testAFaultWithAnEmptyDetailElementIsAFailedCall(): void
    {
        // ext-soap emits the declared fault element even with no detail, as an empty element
        $client = new StockAvailabilityClient(new FixedResponseSoapClient(
            self::ENVELOPE_OPEN
            . '<SOAP-ENV:Fault><faultcode>SOAP-ENV:Server</faultcode>'
            . '<faultstring>Internal error.</faultstring>'
            . '<detail><ns1:StockAvailabilityFault/></detail></SOAP-ENV:Fault>'
            . self::ENVELOPE_CLOSE,
        ));

        $this->expectException(SoapCallFailedException::class);
        $this->expectExceptionMessage('getStockAvailability failed: Internal error.');

        $client->getStockAvailability($this->buildAvailabilityRequest());
    }

    public function testAFaultWithNoDetailAtAllIsAFailedCall(): void
    {
        // the envelope StockAvailabilityController answers with when handle() itself fails
        $client = new StockAvailabilityClient(new FixedResponseSoapClient(
            self::ENVELOPE_OPEN
            . '<SOAP-ENV:Fault><faultcode>SOAP-ENV:Server</faultcode>'
            . '<faultstring>Internal Error</faultstring></SOAP-ENV:Fault>'
            . self::ENVELOPE_CLOSE,
        ));

        $this->expectException(SoapCallFailedException::class);
        $this->expectExceptionMessage('getStockAvailability failed: Internal Error');

        $client->getStockAvailability($this->buildAvailabilityRequest());
    }

    public function testAFaultCodeOutsideTheEnumerationIsAFailedCall(): void
    {
        $client = new StockAvailabilityClient(new FixedResponseSoapClient(
            self::ENVELOPE_OPEN
            . '<SOAP-ENV:Fault><faultcode>SOAP-ENV:Client</faultcode>'
            . '<faultstring>A code this client does not know.</faultstring>'
            . '<detail><ns1:StockAvailabilityFault>'
            . '<ns1:code>NOT_IN_ENUM</ns1:code>'
            . '</ns1:StockAvailabilityFault></detail></SOAP-ENV:Fault>'
            . self::ENVELOPE_CLOSE,
        ));

        $this->expectException(SoapCallFailedException::class);
        $this->expectExceptionMessage('getStockAvailability failed: A code this client does not know.');

        $client->getStockAvailability($this->buildAvailabilityRequest());
    }
}
