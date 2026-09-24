<?php

declare(strict_types=1);

namespace App\StockAvailability;

use App\StockAvailability\Contract\CreateOrderRequest;
use App\StockAvailability\Contract\CreateOrderResponse;
use App\StockAvailability\Contract\GetStockAvailabilityRequest;
use App\StockAvailability\Contract\GetStockAvailabilityResponse;
use App\StockAvailability\Contract\OrderItem;
use App\StockAvailability\Contract\ProductAvailability;
use App\StockAvailability\Contract\StockAvailabilityFault;

use const SOAP_SINGLE_ELEMENT_ARRAYS;
use const WSDL_CACHE_NONE;

final class StockAvailabilityContract
{
    public const string WSDL_PATH = __DIR__ . '/../../config/soap/stock-availability.wsdl';

    public const string XSD_NAMESPACE = 'http://www.w3.org/2001/XMLSchema';

    public const string TARGET_NAMESPACE = 'urn:soap-availability:stock:v1';

    public const string SOAP_11_ENVELOPE_NAMESPACE = 'http://schemas.xmlsoap.org/soap/envelope/';

    public const string SOAP_12_ENVELOPE_NAMESPACE = 'http://www.w3.org/2003/05/soap-envelope';

    public const string WSDL_SOAP_NAMESPACE = 'http://schemas.xmlsoap.org/wsdl/soap/';

    /**
     * The wording ext-soap itself uses with send_errors off, so a partner sees one string whichever
     * path fails.
     */
    public const string SERVER_FAULT_STRING = 'Internal Error';

    public const array CLASSMAP = [
        'getStockAvailability' => GetStockAvailabilityRequest::class,
        'getStockAvailabilityResponse' => GetStockAvailabilityResponse::class,
        'ProductAvailability' => ProductAvailability::class,
        'OrderItem' => OrderItem::class,
        'createOrder' => CreateOrderRequest::class,
        'createOrderResponse' => CreateOrderResponse::class,
        'StockAvailabilityFault' => StockAvailabilityFault::class,
    ];

    /**
     * Without SOAP_SINGLE_ELEMENT_ARRAYS a single productCode arrives as a string and the typed
     * array property throws.
     *
     * @return array<string, mixed>
     */
    public static function buildSoapOptions(): array
    {
        return [
            'classmap' => self::CLASSMAP,
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
            'cache_wsdl' => WSDL_CACHE_NONE,
        ];
    }
}
