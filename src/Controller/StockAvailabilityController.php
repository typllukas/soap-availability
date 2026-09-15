<?php

declare(strict_types=1);

namespace App\Controller;

use App\StockAvailability\Contract\StockAvailabilityFaultCode;
use App\StockAvailability\Server\LoggingStockAvailabilityOperations;
use App\StockAvailability\Server\StockAvailabilitySchemaValidator;
use App\StockAvailability\StockAvailabilityContract;
use DOMDocument;
use LogicException;
use Psr\Log\LoggerInterface;
use SoapServer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

use function array_any;
use function htmlspecialchars;
use function ob_end_clean;
use function ob_get_clean;
use function ob_start;
use function sprintf;
use function str_contains;
use function strtolower;

use const ENT_XML1;

/**
 * The service's one address: GET returns the WSDL, POST runs the operations.
 */
final readonly class StockAvailabilityController
{
    /**
     * SoapServer picks its version from the request envelope whatever the WSDL binds, so the response
     * has to follow it: SOAP 1.2 is rejected outright by a client that receives it as text/xml.
     */
    private const string SOAP_11_CONTENT_TYPE = 'text/xml; charset=utf-8';

    private const string SOAP_12_CONTENT_TYPE = 'application/soap+xml; charset=utf-8';

    /**
     * Answered when even handle() fails, so that a partner never receives an HTML error page.
     */
    private const string SOAP_11_SERVER_FAULT_ENVELOPE = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Body>'
        . '<SOAP-ENV:Fault><faultcode>SOAP-ENV:Server</faultcode>'
        . '<faultstring>' . StockAvailabilityContract::SERVER_FAULT_STRING . '</faultstring></SOAP-ENV:Fault>'
        . '</SOAP-ENV:Body></SOAP-ENV:Envelope>';

    private const string SOAP_11_CLIENT_FAULT_ENVELOPE = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
        . ' xmlns:ns1="' . StockAvailabilityContract::TARGET_NAMESPACE . '"><SOAP-ENV:Body>'
        . '<SOAP-ENV:Fault><faultcode>SOAP-ENV:Client</faultcode><faultstring>%s</faultstring>'
        . '<detail><ns1:StockAvailabilityFault><ns1:code>%s</ns1:code></ns1:StockAvailabilityFault></detail>'
        . '</SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>';

    private const string SOAP_12_CLIENT_FAULT_ENVELOPE = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<env:Envelope xmlns:env="' . StockAvailabilityContract::SOAP_12_ENVELOPE_NAMESPACE . '"'
        . ' xmlns:ns1="' . StockAvailabilityContract::TARGET_NAMESPACE . '"><env:Body>'
        . '<env:Fault><env:Code><env:Value>env:Sender</env:Value></env:Code>'
        . '<env:Reason><env:Text xml:lang="en">%s</env:Text></env:Reason>'
        . '<env:Detail><ns1:StockAvailabilityFault><ns1:code>%s</ns1:code></ns1:StockAvailabilityFault></env:Detail>'
        . '</env:Fault></env:Body></env:Envelope>';

    private const string SOAP_12_SERVER_FAULT_ENVELOPE = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<env:Envelope xmlns:env="' . StockAvailabilityContract::SOAP_12_ENVELOPE_NAMESPACE . '"><env:Body>'
        . '<env:Fault><env:Code><env:Value>env:Receiver</env:Value></env:Code>'
        . '<env:Reason><env:Text xml:lang="en">' . StockAvailabilityContract::SERVER_FAULT_STRING
        . '</env:Text></env:Reason></env:Fault>'
        . '</env:Body></env:Envelope>';

    public function __construct(
        private LoggingStockAvailabilityOperations $loggingStockAvailabilityOperations,
        private StockAvailabilitySchemaValidator $stockAvailabilitySchemaValidator,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/stock-availability', name: 'stock_availability_wsdl', methods: ['GET'])]
    public function serveWsdl(Request $request): Response
    {
        if (!$this->isContractRequested($request)) {
            throw new NotFoundHttpException('The contract is served on ?wsdl; operations are POSTed to this address.');
        }

        $wsdl = new DOMDocument();
        $wsdl->load(StockAvailabilityContract::WSDL_PATH);

        // svcutil and wsimport bake this address into the generated proxy
        foreach ($wsdl->getElementsByTagNameNS(StockAvailabilityContract::WSDL_SOAP_NAMESPACE, 'address') as $address) {
            $address->setAttribute('location', $request->getSchemeAndHttpHost() . $request->getPathInfo());
        }

        $contract = $wsdl->saveXML();
        if ($contract === false) {
            throw new LogicException('The WSDL is part of the repository and must be serializable.');
        }

        return new Response($contract, Response::HTTP_OK, ['Content-Type' => 'text/xml; charset=utf-8']);
    }

    /**
     * SoapServer writes to the output buffer and sets its status code through the SAPI, and neither
     * survives a Symfony Response, so both are captured here and re-issued.
     */
    #[Route('/stock-availability', name: 'stock_availability', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        $requestBody = $request->getContent();
        $isSoap12 = str_contains($requestBody, StockAvailabilityContract::SOAP_12_ENVELOPE_NAMESPACE);
        $contentType = $isSoap12 ? self::SOAP_12_CONTENT_TYPE : self::SOAP_11_CONTENT_TYPE;

        // before handle(), because what ext-soap cannot decode it answers itself and ends the process
        $violation = $this->stockAvailabilitySchemaValidator->findContractViolation($requestBody);
        if ($violation !== null) {
            return new Response(
                $this->buildInvalidRequestEnvelope($isSoap12, $violation),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['Content-Type' => $contentType],
            );
        }

        $server = new SoapServer(
            StockAvailabilityContract::WSDL_PATH,
            // keeps a PHP message off the wire if an Error arises outside an operation
            [...StockAvailabilityContract::buildSoapOptions(), 'send_errors' => false],
        );
        $server->setObject($this->loggingStockAvailabilityOperations);

        ob_start();
        try {
            $server->handle($requestBody);
            $envelope = ob_get_clean();
            if ($envelope === false) {
                $this->logger->error('The output buffer was gone when the SOAP operation returned.');
                $envelope = $this->buildServerFaultEnvelope($isSoap12);
            }
        } catch (Throwable $throwable) {
            ob_end_clean();
            $this->logger->error(
                'The SOAP server failed outside an operation: {exception_class}: {exception_message}'
                . ' at {exception_location}',
                [
                    'exception_class' => $throwable::class,
                    'exception_message' => $throwable->getMessage(),
                    'exception_location' => sprintf('%s:%d', $throwable->getFile(), $throwable->getLine()),
                ],
            );

            // after handle() has thrown, SoapServer::fault() writes past the buffer and ends the process
            $envelope = $this->buildServerFaultEnvelope($isSoap12);
        }

        // the prefix differs between 1.1 and 1.2, so match the element and not the prefix
        $statusCode = str_contains($envelope, ':Fault>')
            ? Response::HTTP_INTERNAL_SERVER_ERROR
            : Response::HTTP_OK;

        return new Response($envelope, $statusCode, ['Content-Type' => $contentType]);
    }

    /**
     * Partners spell the query string both ways, and this repository's own foreign client asks for ?WSDL.
     */
    private function isContractRequested(Request $request): bool
    {
        return array_any(
            $request->query->keys(),
            static fn (string $queryParameterName): bool => strtolower($queryParameterName) === 'wsdl',
        );
    }

    private function buildInvalidRequestEnvelope(bool $isSoap12, string $violation): string
    {
        $template = $isSoap12 ? self::SOAP_12_CLIENT_FAULT_ENVELOPE : self::SOAP_11_CLIENT_FAULT_ENVELOPE;

        return sprintf(
            $template,
            htmlspecialchars('The message does not match the contract: ' . $violation, ENT_XML1),
            StockAvailabilityFaultCode::INVALID_REQUEST->value,
        );
    }

    private function buildServerFaultEnvelope(bool $isSoap12): string
    {
        return $isSoap12 ? self::SOAP_12_SERVER_FAULT_ENVELOPE : self::SOAP_11_SERVER_FAULT_ENVELOPE;
    }
}
