<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Services;

use Kanvas\Connectors\Reynolds\Exceptions\ReynoldsException;
use SimpleXMLElement;

class XmlParser
{
    public static function toArray(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        $element = simplexml_load_string(
            $xml,
            SimpleXMLElement::class,
            LIBXML_NOCDATA | LIBXML_NOBLANKS
        );

        if ($element === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            throw new ReynoldsException(
                'Invalid XML payload: ' . ($errors[0]->message ?? 'unknown error')
            );
        }

        libxml_use_internal_errors($previous);

        return self::elementToArray($element);
    }

    private static function elementToArray(SimpleXMLElement $element): array
    {
        $result = json_decode(json_encode($element), true);

        return is_array($result) ? $result : [];
    }

    /**
     * Extracts the payload from a SOAP envelope.
     *
     * Reynolds wraps everything in soap:Envelope > soap:Body > PutMessage/ProcessMessage
     * > payload > content > rey_*. This helper strips the envelope and returns the
     * inner rey_* element as an array.
     */
    public static function extractPayloadFromEnvelope(string $xml): array
    {
        $parsed = self::toArray(self::stripNamespaces($xml));

        $body = $parsed['Body'] ?? $parsed;
        $operation = $body['PutMessage'] ?? $body['ProcessMessage'] ?? $body;
        $payload = $operation['payload'] ?? $operation;
        $content = $payload['content'] ?? $payload;

        if (! is_array($content)) {
            return [];
        }

        foreach ($content as $key => $value) {
            if (str_starts_with((string) $key, 'rey_') && is_array($value)) {
                return $value;
            }
        }

        return $content;
    }

    /**
     * Removes XML namespace prefixes so SimpleXML can read keys without colons.
     */
    private static function stripNamespaces(string $xml): string
    {
        return (string) preg_replace('/(<\/?)[a-zA-Z0-9]+:/', '$1', $xml);
    }
}
