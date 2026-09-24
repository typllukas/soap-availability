<?php

declare(strict_types=1);

namespace App\Tests\Unit\StockAvailability;

use App\StockAvailability\Contract\StockAvailabilityFaultCode;
use App\StockAvailability\StockAvailabilityContract;
use DOMDocument;
use DOMNodeList;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use SoapClient;
use SoapServer;

use function array_keys;
use function sort;

final class StockAvailabilityContractTest extends TestCase
{
    public function testTheWsdlDeclaresBothOperationsAsWrappedDocumentLiteral(): void
    {
        $client = new SoapClient(StockAvailabilityContract::WSDL_PATH, StockAvailabilityContract::buildSoapOptions());

        self::assertSame(
            [
                'getStockAvailabilityResponse getStockAvailability(getStockAvailability $parameters)',
                'createOrderResponse createOrder(createOrder $parameters)',
            ],
            $client->__getFunctions(),
        );
    }

    /**
     * The constructor is the parse, and a broken WSDL ends the process instead of throwing, so
     * reaching the end of this test is the whole assertion.
     */
    public function testTheWsdlIsAcceptedByTheServer(): void
    {
        $this->expectNotToPerformAssertions();

        new SoapServer(StockAvailabilityContract::WSDL_PATH, StockAvailabilityContract::buildSoapOptions());
    }

    public function testTheClassmapAndTheSchemaNameTheSameDeclarations(): void
    {
        $declaredNames = $this->queryAttributeValues(
            '//xsd:schema/xsd:element/@name | //xsd:schema/xsd:complexType/@name',
        );
        $classmapKeys = array_keys(StockAvailabilityContract::CLASSMAP);

        sort($declaredNames);
        sort($classmapKeys);
        self::assertSame($declaredNames, $classmapKeys);
    }

    public function testTheSchemaEnumerationHoldsTheSameCodesAsTheFaultCodeEnum(): void
    {
        $declaredCodes = $this->queryAttributeValues(
            '//xsd:simpleType[@name="StockAvailabilityFaultCode"]/xsd:restriction/xsd:enumeration/@value',
        );

        $enumCodes = [];
        foreach (StockAvailabilityFaultCode::cases() as $faultCode) {
            $enumCodes[] = $faultCode->value;
        }

        sort($declaredCodes);
        sort($enumCodes);
        self::assertSame($enumCodes, $declaredCodes);
    }

    /**
     * @return list<string>
     */
    private function queryAttributeValues(string $expression): array
    {
        $document = new DOMDocument();
        self::assertTrue($document->load(StockAvailabilityContract::WSDL_PATH));

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('xsd', 'http://www.w3.org/2001/XMLSchema');

        $attributes = $xpath->query($expression);
        self::assertInstanceOf(DOMNodeList::class, $attributes);

        $values = [];
        foreach ($attributes as $attribute) {
            self::assertNotNull($attribute->nodeValue);
            $values[] = $attribute->nodeValue;
        }

        return $values;
    }
}
