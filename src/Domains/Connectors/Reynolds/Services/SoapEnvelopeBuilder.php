<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Services;

use Ramsey\Uuid\Uuid;

class SoapEnvelopeBuilder
{
    private const STAR_NAMESPACE = 'http://www.starstandards.org/STAR';
    private const TRANSPORT_NAMESPACE = 'http://www.starstandards.org/webservices/2005/10/transport';
    private const SOAP_NAMESPACE = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const WSSE_NAMESPACE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

    /**
     * Build a SOAP envelope wrapping a `rey_*` payload for the ProcessMessage operation.
     *
     * @param  string  $rootElement  e.g. "rey_SalesAssistCRMInsertSalesLead"
     * @param  array   $payload      the inner record / application area data
     */
    public static function buildProcessMessage(
        string $rootElement,
        array $payload,
        string $username,
        string $password
    ): string {
        $contentId = 'Content-' . Uuid::uuid4()->toString();
        $bodyXml = self::arrayToXml($rootElement, $payload, self::STAR_NAMESPACE);

        $soapNs = self::SOAP_NAMESPACE;
        $wsseNs = self::WSSE_NAMESPACE;
        $starNs = self::STAR_NAMESPACE;
        $transportNs = self::TRANSPORT_NAMESPACE;

        $escapedUsername = self::escape($username);
        $escapedPassword = self::escape($password);

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<soapenv:Envelope xmlns:soapenv="{$soapNs}" xmlns:star="{$starNs}">
    <soapenv:Header>
        <wsse:Security soapenv:mustUnderstand="1" xmlns:wsse="{$wsseNs}">
            <wsse:UsernameToken>
                <wsse:Username>{$escapedUsername}</wsse:Username>
                <wsse:Password>{$escapedPassword}</wsse:Password>
            </wsse:UsernameToken>
        </wsse:Security>
        <payloadManifest xmlns="{$transportNs}">
            <manifest ContentID="{$contentId}" namespaceURI="{$starNs}" element="{$rootElement}"/>
        </payloadManifest>
    </soapenv:Header>
    <soapenv:Body>
        <ProcessMessage xmlns="{$transportNs}">
            <payload>
                <content id="{$contentId}">
                    {$bodyXml}
                </content>
            </payload>
        </ProcessMessage>
    </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    /**
     * Convert an associative array to XML respecting Reynolds' element ordering.
     * Reynolds is strict about XSD sequence order — pass arrays already in correct order.
     */
    public static function arrayToXml(string $rootElement, array $data, string $namespace): string
    {
        $xml = "<{$rootElement} xmlns=\"{$namespace}\">";
        $xml .= self::renderChildren($data);
        $xml .= "</{$rootElement}>";

        return $xml;
    }

    private static function renderChildren(array $data): string
    {
        $xml = '';

        foreach ($data as $key => $value) {
            if (is_int($key)) {
                continue;
            }

            if (is_array($value)) {
                if (self::isList($value)) {
                    foreach ($value as $item) {
                        $xml .= "<{$key}>" . (is_array($item) ? self::renderChildren($item) : self::escape((string) $item)) . "</{$key}>";
                    }
                } else {
                    $xml .= "<{$key}>" . self::renderChildren($value) . "</{$key}>";
                }

                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $xml .= "<{$key}>" . self::escape((string) $value) . "</{$key}>";
        }

        return $xml;
    }

    private static function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
