<?php

declare(strict_types=1);

namespace App\StockAvailability\Server;

use App\StockAvailability\StockAvailabilityContract;
use DOMDocument;
use DOMElement;
use LibXMLError;
use LogicException;

use function libxml_clear_errors;
use function libxml_get_errors;
use function libxml_use_internal_errors;
use function trim;

/**
 * ext-soap does not check a message against the schema, and what it cannot decode it answers past
 * the controller.
 */
final readonly class StockAvailabilitySchemaValidator
{
    public function findContractViolation(string $requestBody): ?string
    {
        $payload = $this->readBodyPayload($requestBody);
        if (!$payload instanceof DOMElement) {
            return null;
        }

        $payloadDocument = new DOMDocument();
        $payloadDocument->appendChild($payloadDocument->importNode($payload, true));

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $isValid = $payloadDocument->schemaValidateSource($this->readSchema());
        $firstError = libxml_get_errors()[0] ?? null;
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        if ($isValid) {
            return null;
        }

        return $firstError instanceof LibXMLError
            ? trim($firstError->message)
            : 'the message does not match the schema';
    }

    /**
     * A body ext-soap will reject on its own terms is left to it, so that this stays a schema check.
     */
    private function readBodyPayload(string $requestBody): ?DOMElement
    {
        if (trim($requestBody) === '') {
            return null;
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $envelope = new DOMDocument();
        $isWellFormed = $envelope->loadXML($requestBody);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        if (!$isWellFormed) {
            return null;
        }

        foreach (
            [
                StockAvailabilityContract::SOAP_11_ENVELOPE_NAMESPACE,
                StockAvailabilityContract::SOAP_12_ENVELOPE_NAMESPACE,
            ] as $envelopeNamespace
        ) {
            $body = $envelope->getElementsByTagNameNS($envelopeNamespace, 'Body')->item(0);
            if (!$body instanceof DOMElement) {
                continue;
            }

            foreach ($body->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    return $child;
                }
            }
        }

        return null;
    }

    private function readSchema(): string
    {
        $wsdl = new DOMDocument();
        $wsdl->load(StockAvailabilityContract::WSDL_PATH);

        $schemaElement = $wsdl->getElementsByTagNameNS(StockAvailabilityContract::XSD_NAMESPACE, 'schema')->item(0);
        if (!$schemaElement instanceof DOMElement) {
            throw new LogicException('The WSDL of this repository always carries one inline schema.');
        }

        $schemaDocument = new DOMDocument();
        $schemaDocument->appendChild($schemaDocument->importNode($schemaElement, true));

        $schema = $schemaDocument->saveXML();
        if ($schema === false) {
            throw new LogicException('A schema lifted out of the WSDL is always serializable.');
        }

        return $schema;
    }
}
